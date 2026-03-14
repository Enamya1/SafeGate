<?php

namespace App\Http\Controllers;

use App\Models\ExchangeProduct;
use App\Models\ExchangeTransaction;
use App\Models\Product;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ExchangeTransactionController extends Controller
{
    private function appendTimeline(?array $timeline, string $status, int $userId, ?string $note = null): array
    {
        $timeline = is_array($timeline) ? $timeline : [];
        $timeline[] = [
            'status' => $status,
            'user_id' => $userId,
            'note' => $note,
            'at' => now()->toIso8601String(),
        ];

        return $timeline;
    }

    private function notifyUser(int $userId, string $type, array $payload): void
    {
        DB::table('notifications')->insert([
            'id' => (string) Str::uuid(),
            'type' => $type,
            'notifiable_type' => User::class,
            'notifiable_id' => $userId,
            'data' => json_encode($payload),
            'read_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function store(Request $request)
    {
        $user = $request->user();

        if (($user->role ?? 'user') !== 'user') {
            return response()->json([
                'message' => 'Unauthorized: Only users can access this endpoint.',
            ], 403);
        }

        try {
            $validated = $request->validate([
                'offered_product_id' => 'required|integer|exists:products,id',
                'requested_product_id' => 'required|integer|exists:products,id',
                'exchange_terms' => 'nullable|array',
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Validation Error',
                'errors' => $e->errors(),
            ], 422);
        }

        $offeredProduct = Product::query()->whereKey($validated['offered_product_id'])->first();
        $requestedProduct = Product::query()->whereKey($validated['requested_product_id'])->first();

        if (! $offeredProduct || ! $requestedProduct) {
            return response()->json([
                'message' => 'Product not found.',
            ], 404);
        }

        if ((int) $offeredProduct->id === (int) $requestedProduct->id) {
            return response()->json([
                'message' => 'Offered and requested products must be different.',
            ], 422);
        }

        if ((int) $offeredProduct->seller_id === (int) $user->id) {
            return response()->json([
                'message' => 'Cannot initiate exchange with your own listing.',
            ], 422);
        }

        if ((int) $requestedProduct->seller_id !== (int) $user->id) {
            return response()->json([
                'message' => 'Requested product must belong to the initiator.',
            ], 403);
        }

        if ($offeredProduct->status !== 'available' || $requestedProduct->status !== 'available') {
            return response()->json([
                'message' => 'One or more products are unavailable.',
            ], 409);
        }

        $exchangeProduct = ExchangeProduct::query()
            ->where('product_id', $offeredProduct->id)
            ->first();

        if (! $exchangeProduct) {
            return response()->json([
                'message' => 'Exchange listing not found for offered product.',
            ], 404);
        }

        if ($exchangeProduct->exchange_status !== 'open') {
            return response()->json([
                'message' => 'Exchange listing is not open.',
            ], 409);
        }

        if ($exchangeProduct->expiration_date && $exchangeProduct->expiration_date->isPast()) {
            return response()->json([
                'message' => 'Exchange listing has expired.',
            ], 409);
        }

        $hasActive = ExchangeTransaction::query()
            ->where('offered_product_id', $offeredProduct->id)
            ->whereIn('status', ['pending', 'negotiating', 'accepted'])
            ->exists();

        if ($hasActive) {
            return response()->json([
                'message' => 'An active exchange already exists for this listing.',
            ], 409);
        }

        $hasRequestedActive = ExchangeTransaction::query()
            ->where('requested_product_id', $requestedProduct->id)
            ->whereIn('status', ['pending', 'negotiating', 'accepted'])
            ->exists();

        if ($hasRequestedActive) {
            return response()->json([
                'message' => 'Requested product is already in an active exchange.',
            ], 409);
        }

        $transaction = DB::transaction(function () use ($validated, $user, $offeredProduct, $requestedProduct, $exchangeProduct) {
            $timeline = $this->appendTimeline(null, 'pending', (int) $user->id, 'Exchange request created');
            $transaction = ExchangeTransaction::create([
                'initiator_id' => $user->id,
                'responder_id' => $offeredProduct->seller_id,
                'offered_product_id' => $offeredProduct->id,
                'requested_product_id' => $requestedProduct->id,
                'exchange_terms' => $validated['exchange_terms'] ?? null,
                'status' => 'pending',
                'status_timeline' => $timeline,
            ]);

            $exchangeProduct->exchange_status = 'pending';
            $exchangeProduct->save();

            return $transaction;
        });

        $this->notifyUser((int) $transaction->responder_id, 'exchange_request', [
            'exchange_transaction_id' => (int) $transaction->id,
            'offered_product_id' => (int) $transaction->offered_product_id,
            'requested_product_id' => (int) $transaction->requested_product_id,
            'initiator_id' => (int) $transaction->initiator_id,
        ]);

        return response()->json([
            'message' => 'Exchange request created successfully',
            'exchange_transaction' => $transaction,
        ], 201);
    }

    public function updateStatus(Request $request, string $id)
    {
        $user = $request->user();

        if (($user->role ?? 'user') !== 'user') {
            return response()->json([
                'message' => 'Unauthorized: Only users can access this endpoint.',
            ], 403);
        }

        try {
            $validated = $request->validate([
                'status' => 'required|string|in:accept,reject,cancel,complete,negotiate',
                'note' => 'nullable|string|max:500',
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Validation Error',
                'errors' => $e->errors(),
            ], 422);
        }

        $transaction = ExchangeTransaction::query()->whereKey($id)->first();

        if (! $transaction) {
            return response()->json([
                'message' => 'Exchange transaction not found.',
            ], 404);
        }

        $userId = (int) $user->id;
        $isInitiator = $userId === (int) $transaction->initiator_id;
        $isResponder = $userId === (int) $transaction->responder_id;

        if (! $isInitiator && ! $isResponder) {
            return response()->json([
                'message' => 'Unauthorized: You are not part of this exchange.',
            ], 403);
        }

        if (in_array($transaction->status, ['completed', 'cancelled', 'rejected'], true)) {
            return response()->json([
                'message' => 'Exchange is already finalized.',
            ], 409);
        }

        $action = $validated['status'];
        $note = $validated['note'] ?? null;

        $transaction = DB::transaction(function () use ($transaction, $action, $note, $userId, $isInitiator, $isResponder) {
            $exchangeProduct = ExchangeProduct::query()
                ->where('product_id', $transaction->offered_product_id)
                ->first();

            if ($action === 'accept') {
                if ($isInitiator && $transaction->initiator_accepted_at === null) {
                    $transaction->initiator_accepted_at = now();
                }

                if ($isResponder && $transaction->responder_accepted_at === null) {
                    $transaction->responder_accepted_at = now();
                }

                if ($transaction->initiator_accepted_at && $transaction->responder_accepted_at) {
                    $transaction->status = 'accepted';
                    if ($exchangeProduct) {
                        $exchangeProduct->exchange_status = 'accepted';
                        $exchangeProduct->save();
                    }
                } else {
                    $transaction->status = 'pending';
                }
            } elseif ($action === 'negotiate') {
                $transaction->status = 'negotiating';
                if ($exchangeProduct) {
                    $exchangeProduct->exchange_status = 'pending';
                    $exchangeProduct->save();
                }
            } elseif ($action === 'reject') {
                $transaction->status = 'rejected';
                if ($exchangeProduct) {
                    $exchangeProduct->exchange_status = $exchangeProduct->expiration_date && $exchangeProduct->expiration_date->isPast()
                        ? 'expired'
                        : 'open';
                    $exchangeProduct->save();
                }
            } elseif ($action === 'cancel') {
                $transaction->status = 'cancelled';
                if ($exchangeProduct) {
                    $exchangeProduct->exchange_status = $exchangeProduct->expiration_date && $exchangeProduct->expiration_date->isPast()
                        ? 'expired'
                        : 'open';
                    $exchangeProduct->save();
                }
            } elseif ($action === 'complete') {
                if ($transaction->status !== 'accepted') {
                    throw ValidationException::withMessages([
                        'status' => ['Exchange must be accepted before completion.'],
                    ]);
                }

                $transaction->status = 'completed';
                $transaction->completed_at = now();

                if ($exchangeProduct) {
                    $exchangeProduct->exchange_status = 'completed';
                    $exchangeProduct->save();
                }

                Product::query()->whereKey($transaction->offered_product_id)->update([
                    'status' => 'exchanged',
                ]);
                Product::query()->whereKey($transaction->requested_product_id)->update([
                    'status' => 'exchanged',
                ]);
            }

            $transaction->status_timeline = $this->appendTimeline(
                $transaction->status_timeline,
                $transaction->status,
                $userId,
                $note
            );
            $transaction->save();

            return $transaction;
        });

        $notifyUserId = $userId === (int) $transaction->initiator_id
            ? (int) $transaction->responder_id
            : (int) $transaction->initiator_id;

        $this->notifyUser($notifyUserId, 'exchange_status_update', [
            'exchange_transaction_id' => (int) $transaction->id,
            'status' => $transaction->status,
        ]);

        return response()->json([
            'message' => 'Exchange status updated successfully',
            'exchange_transaction' => $transaction,
        ], 200);
    }

    public function userHistory(Request $request, string $userId)
    {
        $user = $request->user();

        if (($user->role ?? 'user') !== 'user') {
            return response()->json([
                'message' => 'Unauthorized: Only users can access this endpoint.',
            ], 403);
        }

        if ((int) $userId !== (int) $user->id) {
            return response()->json([
                'message' => 'Unauthorized: You can only view your own exchange history.',
            ], 403);
        }

        try {
            $validated = $request->validate([
                'limit' => 'nullable|integer|min:1|max:100',
                'status' => 'nullable|string|max:40',
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Validation Error',
                'errors' => $e->errors(),
            ], 422);
        }

        $limit = (int) ($validated['limit'] ?? 50);

        $query = ExchangeTransaction::query()
            ->where(function ($q) use ($userId) {
                $q->where('initiator_id', (int) $userId)
                    ->orWhere('responder_id', (int) $userId);
            })
            ->orderByDesc('id');

        if (! empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        $transactions = $query->limit($limit)->get();

        $productIds = $transactions
            ->flatMap(function (ExchangeTransaction $transaction) {
                return [$transaction->offered_product_id, $transaction->requested_product_id];
            })
            ->unique()
            ->values();

        $productsById = Product::query()
            ->whereIn('id', $productIds->all())
            ->get()
            ->keyBy('id');

        $imagesByProductId = DB::table('product_images')
            ->whereIn('product_id', $productIds->all())
            ->orderByDesc('is_primary')
            ->orderBy('id')
            ->get()
            ->groupBy('product_id');

        $payload = $transactions->map(function (ExchangeTransaction $transaction) use ($productsById, $imagesByProductId) {
            $offered = $productsById->get($transaction->offered_product_id);
            $requested = $productsById->get($transaction->requested_product_id);

            return [
                'exchange_transaction' => $transaction,
                'offered_product' => $offered ? [
                    'id' => (int) $offered->id,
                    'title' => $offered->title,
                    'price' => $offered->price !== null ? (float) $offered->price : null,
                    'currency' => strtoupper((string) ($offered->currency ?? 'CNY')),
                    'status' => $offered->status,
                    'images' => ($imagesByProductId->get($offered->id) ?? collect())->values(),
                ] : null,
                'requested_product' => $requested ? [
                    'id' => (int) $requested->id,
                    'title' => $requested->title,
                    'price' => $requested->price !== null ? (float) $requested->price : null,
                    'currency' => strtoupper((string) ($requested->currency ?? 'CNY')),
                    'status' => $requested->status,
                    'images' => ($imagesByProductId->get($requested->id) ?? collect())->values(),
                ] : null,
            ];
        })->values();

        return response()->json([
            'message' => 'Exchange history retrieved successfully',
            'user_id' => (int) $userId,
            'exchanges' => $payload,
        ], 200);
    }

    public function listMessages(Request $request, string $id)
    {
        $user = $request->user();

        if (($user->role ?? 'user') !== 'user') {
            return response()->json([
                'message' => 'Unauthorized: Only users can access this endpoint.',
            ], 403);
        }

        $exchange = ExchangeTransaction::query()->whereKey($id)->first();

        if (! $exchange) {
            return response()->json([
                'message' => 'Exchange transaction not found.',
            ], 404);
        }

        if ((int) $exchange->initiator_id !== (int) $user->id && (int) $exchange->responder_id !== (int) $user->id) {
            return response()->json([
                'message' => 'Unauthorized: You are not part of this exchange.',
            ], 403);
        }

        try {
            $validated = $request->validate([
                'limit' => 'nullable|integer|min:1|max:100',
                'before_id' => 'nullable|integer|min:1',
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Validation Error',
                'errors' => $e->errors(),
            ], 422);
        }

        $limit = (int) ($validated['limit'] ?? 50);

        $messagesQuery = DB::table('exchange_messages')
            ->join('users', 'exchange_messages.sender_id', '=', 'users.id')
            ->where('exchange_messages.exchange_id', (int) $exchange->id)
            ->select([
                'exchange_messages.id',
                'exchange_messages.exchange_id',
                'exchange_messages.sender_id',
                'users.username as sender_username',
                'users.profile_picture as sender_profile_picture',
                'exchange_messages.message_type',
                'exchange_messages.message_text',
                'exchange_messages.negotiation_details',
                'exchange_messages.created_at',
            ])
            ->orderByDesc('exchange_messages.id')
            ->limit($limit);

        if (array_key_exists('before_id', $validated) && $validated['before_id'] !== null) {
            $messagesQuery->where('exchange_messages.id', '<', (int) $validated['before_id']);
        }

        $messages = $messagesQuery->get()->reverse()->values();

        return response()->json([
            'message' => 'Exchange messages retrieved successfully',
            'exchange_id' => (int) $exchange->id,
            'messages' => $messages,
        ], 200);
    }

    public function sendMessage(Request $request, string $id)
    {
        $user = $request->user();

        if (($user->role ?? 'user') !== 'user') {
            return response()->json([
                'message' => 'Unauthorized: Only users can access this endpoint.',
            ], 403);
        }

        $exchange = ExchangeTransaction::query()->whereKey($id)->first();

        if (! $exchange) {
            return response()->json([
                'message' => 'Exchange transaction not found.',
            ], 404);
        }

        if ((int) $exchange->initiator_id !== (int) $user->id && (int) $exchange->responder_id !== (int) $user->id) {
            return response()->json([
                'message' => 'Unauthorized: You are not part of this exchange.',
            ], 403);
        }

        try {
            $validated = $request->validate([
                'message_type' => 'nullable|string|max:40',
                'message_text' => 'nullable|string|max:2000',
                'negotiation_details' => 'nullable|array',
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Validation Error',
                'errors' => $e->errors(),
            ], 422);
        }

        $messageText = $validated['message_text'] ?? null;
        $negotiationDetails = $validated['negotiation_details'] ?? null;

        if ($messageText === null && $negotiationDetails === null) {
            return response()->json([
                'message' => 'Validation Error',
                'errors' => [
                    'message_text' => ['A message_text or negotiation_details payload is required.'],
                ],
            ], 422);
        }

        $messageType = $validated['message_type'] ?? ($negotiationDetails !== null ? 'negotiation' : 'text');

        $messageId = DB::table('exchange_messages')->insertGetId([
            'exchange_id' => $exchange->id,
            'sender_id' => $user->id,
            'message_type' => $messageType,
            'message_text' => $messageText,
            'negotiation_details' => $negotiationDetails ? json_encode($negotiationDetails) : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $message = DB::table('exchange_messages')->where('id', $messageId)->first();

        $notifyUserId = (int) $exchange->initiator_id === (int) $user->id
            ? (int) $exchange->responder_id
            : (int) $exchange->initiator_id;

        $this->notifyUser($notifyUserId, 'exchange_message', [
            'exchange_transaction_id' => (int) $exchange->id,
            'message_id' => (int) $messageId,
            'message_type' => $messageType,
        ]);

        return response()->json([
            'message' => 'Exchange message sent successfully',
            'exchange_id' => (int) $exchange->id,
            'message_data' => $message,
        ], 201);
    }
}
