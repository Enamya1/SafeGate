<?php

namespace App\Http\Controllers;

use App\Services\FraudDetectionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class WalletController extends Controller
{
    private function userGuard(Request $request)
    {
        $user = $request->user();

        if (($user->role ?? 'user') !== 'user') {
            return [null, response()->json([
                'message' => 'Unauthorized: Only users can access this endpoint.',
            ], 403)];
        }

        return [$user, null];
    }

    private function walletStatusId(string $code): ?int
    {
        return DB::table('wallet_statuses')->where('code', $code)->value('id');
    }

    private function walletTypeId(string $code): ?int
    {
        return DB::table('wallet_types')->where('code', $code)->value('id');
    }

    private function loadWallet(int $walletId, int $userId)
    {
        return DB::table('wallets')
            ->join('wallet_types', 'wallets.wallet_type_id', '=', 'wallet_types.id')
            ->join('wallet_statuses', 'wallets.status_id', '=', 'wallet_statuses.id')
            ->where('wallets.id', $walletId)
            ->where('wallets.user_id', $userId)
            ->select([
                'wallets.id',
                'wallets.user_id',
                'wallets.wallet_type_id',
                'wallets.status_id',
                'wallets.currency',
                'wallets.balance',
                'wallets.available_balance',
                'wallets.locked_balance',
                'wallets.frozen_at',
                'wallets.freeze_reason',
                'wallets.created_at',
                'wallets.updated_at',
                'wallet_types.code as wallet_type_code',
                'wallet_types.name as wallet_type_name',
                'wallet_statuses.code as status_code',
                'wallet_statuses.name as status_name',
            ])
            ->first();
    }

    private function createLedger(array $payload): int
    {
        $ledgerId = DB::table('transaction_ledgers')->insertGetId([
            'ledger_uuid' => (string) Str::uuid(),
            'atomic_transaction_id' => $payload['atomic_transaction_id'] ?? null,
            'wallet_id' => $payload['wallet_id'],
            'related_wallet_id' => $payload['related_wallet_id'] ?? null,
            'direction' => $payload['direction'],
            'amount' => $payload['amount'],
            'currency' => $payload['currency'],
            'status' => $payload['status'] ?? 'completed',
            'type' => $payload['type'] ?? null,
            'reference' => $payload['reference'] ?? null,
            'metadata' => $payload['metadata'] ?? null,
            'occurred_at' => $payload['occurred_at'] ?? now(),
            'initiated_by' => $payload['initiated_by'] ?? null,
            'approved_by' => $payload['approved_by'] ?? null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('transaction_ledger_audits')->insert([
            'transaction_ledger_id' => $ledgerId,
            'action' => $payload['audit_action'] ?? 'created',
            'actor_id' => $payload['initiated_by'] ?? null,
            'payload' => $payload['audit_payload'] ?? null,
            'created_at' => now(),
        ]);

        $fraud = new FraudDetectionService();
        $fraud->evaluateAndRecord([
            'transaction_ledger_id' => $ledgerId,
            'wallet_id' => $payload['wallet_id'],
            'related_wallet_id' => $payload['related_wallet_id'] ?? null,
            'amount' => $payload['amount'],
        ]);

        return $ledgerId;
    }

    public function createWallet(Request $request)
    {
        [$user, $errorResponse] = $this->userGuard($request);
        if ($errorResponse) {
            return $errorResponse;
        }

        try {
            $validated = $request->validate([
                'wallet_type_id' => 'nullable|integer|exists:wallet_types,id',
                'currency' => 'nullable|string|size:3',
                'initial_balance' => 'nullable|numeric|min:0',
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Validation Error',
                'errors' => $e->errors(),
            ], 422);
        }

        $walletTypeId = (int) ($validated['wallet_type_id'] ?? $this->walletTypeId('primary'));
        $statusId = (int) ($this->walletStatusId('active'));
        $currency = strtoupper($validated['currency'] ?? 'CNY');

        if (! $walletTypeId || ! $statusId) {
            return response()->json([
                'message' => 'Wallet configuration missing.',
            ], 500);
        }

        $existing = DB::table('wallets')
            ->where('user_id', $user->id)
            ->where('wallet_type_id', $walletTypeId)
            ->where('currency', $currency)
            ->first();

        if ($existing) {
            return response()->json([
                'message' => 'Wallet already exists.',
                'wallet_id' => $existing->id,
            ], 409);
        }

        $initialBalance = (float) ($validated['initial_balance'] ?? 0);
        $walletId = null;

        DB::transaction(function () use ($user, $walletTypeId, $statusId, $currency, $initialBalance, &$walletId) {
            $walletId = DB::table('wallets')->insertGetId([
                'user_id' => $user->id,
                'wallet_type_id' => $walletTypeId,
                'status_id' => $statusId,
                'currency' => $currency,
                'balance' => 0,
                'available_balance' => 0,
                'locked_balance' => 0,
                'frozen_at' => null,
                'freeze_reason' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if ($initialBalance > 0) {
                DB::table('wallets')->where('id', $walletId)->update([
                    'balance' => $initialBalance,
                    'available_balance' => $initialBalance,
                    'updated_at' => now(),
                ]);

                $this->createLedger([
                    'wallet_id' => $walletId,
                    'direction' => 'credit',
                    'amount' => $initialBalance,
                    'currency' => $currency,
                    'status' => 'completed',
                    'type' => 'initial',
                    'reference' => 'wallet_init',
                    'initiated_by' => $user->id,
                ]);
            }
        });

        $wallet = $this->loadWallet($walletId, $user->id);

        return response()->json([
            'message' => 'Wallet created successfully',
            'wallet' => $wallet,
        ], 201);
    }

    public function listWallets(Request $request)
    {
        [$user, $errorResponse] = $this->userGuard($request);
        if ($errorResponse) {
            return $errorResponse;
        }

        $wallets = DB::table('wallets')
            ->join('wallet_types', 'wallets.wallet_type_id', '=', 'wallet_types.id')
            ->join('wallet_statuses', 'wallets.status_id', '=', 'wallet_statuses.id')
            ->where('wallets.user_id', $user->id)
            ->orderBy('wallets.id')
            ->select([
                'wallets.id',
                'wallets.currency',
                'wallets.balance',
                'wallets.available_balance',
                'wallets.locked_balance',
                'wallets.frozen_at',
                'wallets.freeze_reason',
                'wallets.created_at',
                'wallets.updated_at',
                'wallet_types.code as wallet_type_code',
                'wallet_types.name as wallet_type_name',
                'wallet_statuses.code as status_code',
                'wallet_statuses.name as status_name',
            ])
            ->get();

        return response()->json([
            'message' => 'Wallets retrieved successfully',
            'wallets' => $wallets,
        ], 200);
    }

    public function showWallet(Request $request, string $walletId)
    {
        [$user, $errorResponse] = $this->userGuard($request);
        if ($errorResponse) {
            return $errorResponse;
        }

        $wallet = $this->loadWallet((int) $walletId, $user->id);

        if (! $wallet) {
            return response()->json([
                'message' => 'Wallet not found.',
            ], 404);
        }

        return response()->json([
            'message' => 'Wallet retrieved successfully',
            'wallet' => $wallet,
        ], 200);
    }

    public function updateBalance(Request $request, string $walletId)
    {
        [$user, $errorResponse] = $this->userGuard($request);
        if ($errorResponse) {
            return $errorResponse;
        }

        try {
            $validated = $request->validate([
                'amount' => 'required|numeric|min:0.01',
                'direction' => 'required|string|in:credit,debit',
                'type' => 'nullable|string|max:50',
                'reference' => 'nullable|string|max:100',
                'metadata' => 'nullable|array',
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Validation Error',
                'errors' => $e->errors(),
            ], 422);
        }

        $wallet = null;
        $ledgerId = null;
        $statusActiveId = $this->walletStatusId('active');

        if (! $statusActiveId) {
            return response()->json([
                'message' => 'Wallet status configuration missing.',
            ], 500);
        }

        $amount = (float) $validated['amount'];

        $result = DB::transaction(function () use ($walletId, $user, $amount, $validated, $statusActiveId, &$wallet, &$ledgerId) {
            $walletRow = DB::table('wallets')
                ->where('id', $walletId)
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->first();

            if (! $walletRow) {
                return ['error' => response()->json(['message' => 'Wallet not found.'], 404)];
            }

            if ((int) $walletRow->status_id !== (int) $statusActiveId || $walletRow->frozen_at) {
                return ['error' => response()->json(['message' => 'Wallet is not active.'], 409)];
            }

            $balance = (float) $walletRow->balance;
            $available = (float) $walletRow->available_balance;

            if ($validated['direction'] === 'debit') {
                if ($available < $amount) {
                    return ['error' => response()->json(['message' => 'Insufficient available balance.'], 409)];
                }
                $balance -= $amount;
                $available -= $amount;
            } else {
                $balance += $amount;
                $available += $amount;
            }

            DB::table('wallets')->where('id', $walletRow->id)->update([
                'balance' => $balance,
                'available_balance' => $available,
                'updated_at' => now(),
            ]);

            $ledgerId = $this->createLedger([
                'wallet_id' => $walletRow->id,
                'direction' => $validated['direction'],
                'amount' => $amount,
                'currency' => $walletRow->currency,
                'status' => 'completed',
                'type' => $validated['type'] ?? 'balance_update',
                'reference' => $validated['reference'] ?? null,
                'metadata' => array_key_exists('metadata', $validated) ? json_encode($validated['metadata']) : null,
                'initiated_by' => $user->id,
                'audit_action' => 'balance_update',
            ]);

            $wallet = $this->loadWallet((int) $walletRow->id, $user->id);

            return ['wallet' => $wallet];
        });

        if (isset($result['error'])) {
            return $result['error'];
        }

        return response()->json([
            'message' => 'Wallet balance updated successfully',
            'wallet' => $result['wallet'],
            'ledger_id' => $ledgerId,
        ], 200);
    }

    public function transfer(Request $request)
    {
        [$user, $errorResponse] = $this->userGuard($request);
        if ($errorResponse) {
            return $errorResponse;
        }

        try {
            $validated = $request->validate([
                'from_wallet_id' => 'required|integer|exists:wallets,id',
                'to_wallet_id' => 'required|integer|exists:wallets,id|different:from_wallet_id',
                'amount' => 'required|numeric|min:0.01',
                'reference' => 'nullable|string|max:100',
                'metadata' => 'nullable|array',
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Validation Error',
                'errors' => $e->errors(),
            ], 422);
        }

        $statusActiveId = $this->walletStatusId('active');
        if (! $statusActiveId) {
            return response()->json([
                'message' => 'Wallet status configuration missing.',
            ], 500);
        }

        $amount = (float) $validated['amount'];
        $fromWalletId = (int) $validated['from_wallet_id'];
        $toWalletId = (int) $validated['to_wallet_id'];
        $atomicId = DB::table('atomic_transactions')->insertGetId([
            'atomic_uuid' => (string) Str::uuid(),
            'status' => 'pending',
            'total_amount' => $amount,
            'currency' => null,
            'initiated_by' => $user->id,
            'metadata' => json_encode([
                'type' => 'wallet_transfer',
                'reference' => $validated['reference'] ?? null,
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $result = DB::transaction(function () use ($amount, $fromWalletId, $toWalletId, $statusActiveId, $validated, $user, $atomicId) {
            $walletIds = [$fromWalletId, $toWalletId];
            sort($walletIds);

            $walletRows = DB::table('wallets')
                ->whereIn('id', $walletIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $fromWallet = $walletRows->get($fromWalletId);
            $toWallet = $walletRows->get($toWalletId);

            if (! $fromWallet || ! $toWallet) {
                return ['error' => response()->json(['message' => 'Wallet not found.'], 404)];
            }

            if ((int) $fromWallet->user_id !== (int) $user->id) {
                return ['error' => response()->json(['message' => 'Unauthorized: You can only transfer from your own wallet.'], 403)];
            }

            if ((int) $fromWallet->status_id !== (int) $statusActiveId || $fromWallet->frozen_at) {
                return ['error' => response()->json(['message' => 'Source wallet is not active.'], 409)];
            }

            if ((int) $toWallet->status_id !== (int) $statusActiveId || $toWallet->frozen_at) {
                return ['error' => response()->json(['message' => 'Destination wallet is not active.'], 409)];
            }

            if ($fromWallet->currency !== $toWallet->currency) {
                return ['error' => response()->json(['message' => 'Wallet currencies do not match.'], 409)];
            }

            $fromAvailable = (float) $fromWallet->available_balance;
            if ($fromAvailable < $amount) {
                return ['error' => response()->json(['message' => 'Insufficient available balance.'], 409)];
            }

            DB::table('wallets')->where('id', $fromWalletId)->update([
                'balance' => (float) $fromWallet->balance - $amount,
                'available_balance' => $fromAvailable - $amount,
                'updated_at' => now(),
            ]);

            DB::table('wallets')->where('id', $toWalletId)->update([
                'balance' => (float) $toWallet->balance + $amount,
                'available_balance' => (float) $toWallet->available_balance + $amount,
                'updated_at' => now(),
            ]);

            $debitLedgerId = $this->createLedger([
                'atomic_transaction_id' => $atomicId,
                'wallet_id' => $fromWalletId,
                'related_wallet_id' => $toWalletId,
                'direction' => 'debit',
                'amount' => $amount,
                'currency' => $fromWallet->currency,
                'status' => 'completed',
                'type' => 'transfer',
                'reference' => $validated['reference'] ?? null,
                'metadata' => array_key_exists('metadata', $validated) ? json_encode($validated['metadata']) : null,
                'initiated_by' => $user->id,
                'audit_action' => 'transfer_debit',
            ]);

            $creditLedgerId = $this->createLedger([
                'atomic_transaction_id' => $atomicId,
                'wallet_id' => $toWalletId,
                'related_wallet_id' => $fromWalletId,
                'direction' => 'credit',
                'amount' => $amount,
                'currency' => $toWallet->currency,
                'status' => 'completed',
                'type' => 'transfer',
                'reference' => $validated['reference'] ?? null,
                'metadata' => array_key_exists('metadata', $validated) ? json_encode($validated['metadata']) : null,
                'initiated_by' => $user->id,
                'audit_action' => 'transfer_credit',
            ]);

            DB::table('atomic_transaction_steps')->insert([
                [
                    'atomic_transaction_id' => $atomicId,
                    'step_order' => 1,
                    'step_type' => 'debit',
                    'status' => 'completed',
                    'from_wallet_id' => $fromWalletId,
                    'to_wallet_id' => $toWalletId,
                    'amount' => $amount,
                    'currency' => $fromWallet->currency,
                    'transaction_ledger_id' => $debitLedgerId,
                    'error_message' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                [
                    'atomic_transaction_id' => $atomicId,
                    'step_order' => 2,
                    'step_type' => 'credit',
                    'status' => 'completed',
                    'from_wallet_id' => $fromWalletId,
                    'to_wallet_id' => $toWalletId,
                    'amount' => $amount,
                    'currency' => $toWallet->currency,
                    'transaction_ledger_id' => $creditLedgerId,
                    'error_message' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            ]);

            DB::table('atomic_transactions')->where('id', $atomicId)->update([
                'status' => 'completed',
                'currency' => $fromWallet->currency,
                'updated_at' => now(),
            ]);

            $fromWalletFresh = $this->loadWallet($fromWalletId, $user->id);

            return [
                'debit_ledger_id' => $debitLedgerId,
                'credit_ledger_id' => $creditLedgerId,
                'from_wallet' => $fromWalletFresh,
            ];
        });

        if (isset($result['error'])) {
            DB::table('atomic_transactions')->where('id', $atomicId)->update([
                'status' => 'failed',
                'updated_at' => now(),
            ]);

            return $result['error'];
        }

        return response()->json([
            'message' => 'Transfer completed successfully',
            'atomic_transaction_id' => $atomicId,
            'debit_ledger_id' => $result['debit_ledger_id'],
            'credit_ledger_id' => $result['credit_ledger_id'],
            'wallet' => $result['from_wallet'],
        ], 200);
    }

    public function transactionHistory(Request $request, string $walletId)
    {
        [$user, $errorResponse] = $this->userGuard($request);
        if ($errorResponse) {
            return $errorResponse;
        }

        $wallet = DB::table('wallets')
            ->where('id', $walletId)
            ->where('user_id', $user->id)
            ->first();

        if (! $wallet) {
            return response()->json([
                'message' => 'Wallet not found.',
            ], 404);
        }

        $query = DB::table('transaction_ledgers')
            ->where('wallet_id', $wallet->id)
            ->orderByDesc('occurred_at');

        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->toString());
        }

        if ($request->filled('direction')) {
            $query->where('direction', $request->string('direction')->toString());
        }

        if ($request->filled('type')) {
            $query->where('type', $request->string('type')->toString());
        }

        if ($request->filled('from')) {
            $query->where('occurred_at', '>=', $request->input('from'));
        }

        if ($request->filled('to')) {
            $query->where('occurred_at', '<=', $request->input('to'));
        }

        $limit = (int) $request->input('limit', 50);
        $limit = max(1, min(200, $limit));

        $transactions = $query->limit($limit)->get();

        return response()->json([
            'message' => 'Wallet transactions retrieved successfully',
            'wallet_id' => (int) $wallet->id,
            'transactions' => $transactions,
        ], 200);
    }

    public function statusHistory(Request $request, string $walletId)
    {
        [$user, $errorResponse] = $this->userGuard($request);
        if ($errorResponse) {
            return $errorResponse;
        }

        $wallet = DB::table('wallets')
            ->where('id', $walletId)
            ->where('user_id', $user->id)
            ->first();

        if (! $wallet) {
            return response()->json([
                'message' => 'Wallet not found.',
            ], 404);
        }

        $history = DB::table('wallet_status_histories')
            ->join('wallet_statuses as to_status', 'wallet_status_histories.to_status_id', '=', 'to_status.id')
            ->leftJoin('wallet_statuses as from_status', 'wallet_status_histories.from_status_id', '=', 'from_status.id')
            ->where('wallet_status_histories.wallet_id', $wallet->id)
            ->orderByDesc('wallet_status_histories.created_at')
            ->select([
                'wallet_status_histories.id',
                'wallet_status_histories.reason',
                'wallet_status_histories.created_at',
                'to_status.code as to_status',
                'from_status.code as from_status',
                'wallet_status_histories.changed_by',
            ])
            ->get();

        return response()->json([
            'message' => 'Wallet status history retrieved successfully',
            'wallet_id' => (int) $wallet->id,
            'history' => $history,
        ], 200);
    }

    public function createStatusRequest(Request $request, string $walletId)
    {
        [$user, $errorResponse] = $this->userGuard($request);
        if ($errorResponse) {
            return $errorResponse;
        }

        try {
            $validated = $request->validate([
                'action' => 'required|string|in:freeze,unfreeze',
                'reason' => 'nullable|string|max:500',
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Validation Error',
                'errors' => $e->errors(),
            ], 422);
        }

        $wallet = DB::table('wallets')
            ->where('id', $walletId)
            ->where('user_id', $user->id)
            ->first();

        if (! $wallet) {
            return response()->json([
                'message' => 'Wallet not found.',
            ], 404);
        }

        $requestId = DB::table('wallet_freeze_requests')->insertGetId([
            'wallet_id' => $wallet->id,
            'requested_by' => $user->id,
            'approved_by' => null,
            'action' => $validated['action'],
            'status' => 'pending',
            'reason' => $validated['reason'] ?? null,
            'approved_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json([
            'message' => 'Wallet status request submitted successfully',
            'request_id' => $requestId,
        ], 201);
    }
}
