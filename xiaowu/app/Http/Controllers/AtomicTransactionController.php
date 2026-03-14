<?php

namespace App\Http\Controllers;

use App\Services\FraudDetectionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AtomicTransactionController extends Controller
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

    private function adminGuard(Request $request)
    {
        $admin = $request->user();

        if (! $admin || $admin->role !== 'admin') {
            return [null, response()->json([
                'message' => 'Unauthorized: Only administrators can access this endpoint.',
            ], 403)];
        }

        return [$admin, null];
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

        $fraud = new FraudDetectionService;
        $fraud->evaluateAndRecord([
            'transaction_ledger_id' => $ledgerId,
            'wallet_id' => $payload['wallet_id'],
            'related_wallet_id' => $payload['related_wallet_id'] ?? null,
            'amount' => $payload['amount'],
        ]);

        return $ledgerId;
    }

    public function create(Request $request)
    {
        [$user, $errorResponse] = $this->userGuard($request);
        if ($errorResponse) {
            return $errorResponse;
        }

        try {
            $validated = $request->validate([
                'steps' => 'required|array|min:1',
                'steps.*.step_type' => 'required|string|max:50',
                'steps.*.from_wallet_id' => 'nullable|integer|exists:wallets,id',
                'steps.*.to_wallet_id' => 'nullable|integer|exists:wallets,id',
                'steps.*.amount' => 'required|numeric|min:0.01',
                'steps.*.currency' => 'required|string|size:3',
                'metadata' => 'nullable|array',
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Validation Error',
                'errors' => $e->errors(),
            ], 422);
        }

        foreach ($validated['steps'] as $step) {
            if (empty($step['from_wallet_id']) && empty($step['to_wallet_id'])) {
                return response()->json([
                    'message' => 'Validation Error',
                    'errors' => [
                        'steps' => ['Each step must include from_wallet_id or to_wallet_id.'],
                    ],
                ], 422);
            }
        }

        $totalAmount = array_sum(array_map(fn ($step) => (float) $step['amount'], $validated['steps']));

        $atomicId = DB::table('atomic_transactions')->insertGetId([
            'atomic_uuid' => (string) Str::uuid(),
            'status' => 'pending',
            'total_amount' => $totalAmount,
            'currency' => null,
            'initiated_by' => $user->id,
            'metadata' => array_key_exists('metadata', $validated) ? json_encode($validated['metadata']) : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            DB::transaction(function () use ($validated, $atomicId, $user) {
                $walletIds = [];

                foreach ($validated['steps'] as $step) {
                    if (! empty($step['from_wallet_id'])) {
                        $walletIds[] = (int) $step['from_wallet_id'];
                    }
                    if (! empty($step['to_wallet_id'])) {
                        $walletIds[] = (int) $step['to_wallet_id'];
                    }
                }

                $walletIds = array_values(array_unique($walletIds));
                sort($walletIds);

                $wallets = DB::table('wallets')
                    ->whereIn('id', $walletIds)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');

                $statusActiveId = DB::table('wallet_statuses')->where('code', 'active')->value('id');
                $statusClosedId = DB::table('wallet_statuses')->where('code', 'closed')->value('id');

                if (! $statusActiveId || ! $statusClosedId) {
                    throw new \RuntimeException('Wallet status configuration missing.');
                }

                foreach ($validated['steps'] as $index => $step) {
                    $fromWallet = $step['from_wallet_id'] ? $wallets->get((int) $step['from_wallet_id']) : null;
                    $toWallet = $step['to_wallet_id'] ? $wallets->get((int) $step['to_wallet_id']) : null;
                    $amount = (float) $step['amount'];
                    $currency = strtoupper($step['currency']);

                    if ($fromWallet && $fromWallet->currency !== $currency) {
                        throw new \RuntimeException('Currency mismatch on source wallet.');
                    }
                    if ($toWallet && $toWallet->currency !== $currency) {
                        throw new \RuntimeException('Currency mismatch on destination wallet.');
                    }

                    if ($fromWallet) {
                        if ((int) $fromWallet->status_id === (int) $statusClosedId) {
                            throw new \RuntimeException('Source wallet is closed.');
                        }
                        if ((int) $fromWallet->status_id !== (int) $statusActiveId || $fromWallet->frozen_at) {
                            throw new \RuntimeException('Source wallet is not active.');
                        }
                        if ((float) $fromWallet->available_balance < $amount) {
                            throw new \RuntimeException('Insufficient available balance.');
                        }

                        DB::table('wallets')->where('id', $fromWallet->id)->update([
                            'balance' => (float) $fromWallet->balance - $amount,
                            'available_balance' => (float) $fromWallet->available_balance - $amount,
                            'updated_at' => now(),
                        ]);
                    }

                    if ($toWallet) {
                        if ((int) $toWallet->status_id === (int) $statusClosedId) {
                            throw new \RuntimeException('Destination wallet is closed.');
                        }
                        if ((int) $toWallet->status_id !== (int) $statusActiveId || $toWallet->frozen_at) {
                            throw new \RuntimeException('Destination wallet is not active.');
                        }

                        DB::table('wallets')->where('id', $toWallet->id)->update([
                            'balance' => (float) $toWallet->balance + $amount,
                            'available_balance' => (float) $toWallet->available_balance + $amount,
                            'updated_at' => now(),
                        ]);
                    }

                    $ledgerId = null;

                    if ($fromWallet && $toWallet) {
                        $ledgerId = $this->createLedger([
                            'atomic_transaction_id' => $atomicId,
                            'wallet_id' => $fromWallet->id,
                            'related_wallet_id' => $toWallet->id,
                            'direction' => 'debit',
                            'amount' => $amount,
                            'currency' => $currency,
                            'status' => 'completed',
                            'type' => $step['step_type'],
                            'initiated_by' => $user->id,
                            'audit_action' => 'atomic_step_debit',
                        ]);

                        $this->createLedger([
                            'atomic_transaction_id' => $atomicId,
                            'wallet_id' => $toWallet->id,
                            'related_wallet_id' => $fromWallet->id,
                            'direction' => 'credit',
                            'amount' => $amount,
                            'currency' => $currency,
                            'status' => 'completed',
                            'type' => $step['step_type'],
                            'initiated_by' => $user->id,
                            'audit_action' => 'atomic_step_credit',
                        ]);
                    } elseif ($fromWallet) {
                        $ledgerId = $this->createLedger([
                            'atomic_transaction_id' => $atomicId,
                            'wallet_id' => $fromWallet->id,
                            'direction' => 'debit',
                            'amount' => $amount,
                            'currency' => $currency,
                            'status' => 'completed',
                            'type' => $step['step_type'],
                            'initiated_by' => $user->id,
                            'audit_action' => 'atomic_step_debit',
                        ]);
                    } elseif ($toWallet) {
                        $ledgerId = $this->createLedger([
                            'atomic_transaction_id' => $atomicId,
                            'wallet_id' => $toWallet->id,
                            'direction' => 'credit',
                            'amount' => $amount,
                            'currency' => $currency,
                            'status' => 'completed',
                            'type' => $step['step_type'],
                            'initiated_by' => $user->id,
                            'audit_action' => 'atomic_step_credit',
                        ]);
                    }

                    DB::table('atomic_transaction_steps')->insert([
                        'atomic_transaction_id' => $atomicId,
                        'step_order' => $index + 1,
                        'step_type' => $step['step_type'],
                        'status' => 'completed',
                        'from_wallet_id' => $fromWallet ? $fromWallet->id : null,
                        'to_wallet_id' => $toWallet ? $toWallet->id : null,
                        'amount' => $amount,
                        'currency' => $currency,
                        'transaction_ledger_id' => $ledgerId,
                        'error_message' => null,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                DB::table('atomic_transactions')->where('id', $atomicId)->update([
                    'status' => 'completed',
                    'updated_at' => now(),
                ]);
            });
        } catch (\Throwable $e) {
            DB::table('atomic_transactions')->where('id', $atomicId)->update([
                'status' => 'failed',
                'metadata' => json_encode(['error' => $e->getMessage()]),
                'updated_at' => now(),
            ]);

            return response()->json([
                'message' => 'Atomic transaction failed',
                'error' => $e->getMessage(),
                'atomic_transaction_id' => $atomicId,
            ], 409);
        }

        $transaction = DB::table('atomic_transactions')->where('id', $atomicId)->first();
        $steps = DB::table('atomic_transaction_steps')
            ->where('atomic_transaction_id', $atomicId)
            ->orderBy('step_order')
            ->get();

        return response()->json([
            'message' => 'Atomic transaction completed successfully',
            'atomic_transaction' => $transaction,
            'steps' => $steps,
        ], 201);
    }

    public function show(Request $request, string $atomicId)
    {
        [$user, $errorResponse] = $this->userGuard($request);
        if ($errorResponse) {
            return $errorResponse;
        }

        $transaction = DB::table('atomic_transactions')->where('id', $atomicId)->first();

        if (! $transaction) {
            return response()->json([
                'message' => 'Atomic transaction not found.',
            ], 404);
        }

        $steps = DB::table('atomic_transaction_steps')
            ->where('atomic_transaction_id', $transaction->id)
            ->orderBy('step_order')
            ->get();

        return response()->json([
            'message' => 'Atomic transaction retrieved successfully',
            'atomic_transaction' => $transaction,
            'steps' => $steps,
        ], 200);
    }

    public function verify(Request $request, string $atomicId)
    {
        [$user, $errorResponse] = $this->userGuard($request);
        if ($errorResponse) {
            return $errorResponse;
        }

        $transaction = DB::table('atomic_transactions')->where('id', $atomicId)->first();

        if (! $transaction) {
            return response()->json([
                'message' => 'Atomic transaction not found.',
            ], 404);
        }

        $credits = (float) DB::table('transaction_ledgers')
            ->where('atomic_transaction_id', $transaction->id)
            ->where('direction', 'credit')
            ->sum('amount');

        $debits = (float) DB::table('transaction_ledgers')
            ->where('atomic_transaction_id', $transaction->id)
            ->where('direction', 'debit')
            ->sum('amount');

        $integrity = abs($credits - $debits) < 0.0001;

        return response()->json([
            'message' => 'Atomic transaction verification completed',
            'atomic_transaction_id' => (int) $transaction->id,
            'credits' => $credits,
            'debits' => $debits,
            'integrity_ok' => $integrity,
        ], 200);
    }

    public function rollback(Request $request, string $atomicId)
    {
        [$admin, $errorResponse] = $this->adminGuard($request);
        if ($errorResponse) {
            return $errorResponse;
        }

        $transaction = DB::table('atomic_transactions')->where('id', $atomicId)->first();

        if (! $transaction) {
            return response()->json([
                'message' => 'Atomic transaction not found.',
            ], 404);
        }

        if ($transaction->status !== 'completed') {
            return response()->json([
                'message' => 'Only completed atomic transactions can be rolled back.',
            ], 409);
        }

        $ledgers = DB::table('transaction_ledgers')
            ->where('atomic_transaction_id', $transaction->id)
            ->where('status', 'completed')
            ->orderBy('id')
            ->get();

        if ($ledgers->isEmpty()) {
            return response()->json([
                'message' => 'No ledger entries available for rollback.',
            ], 409);
        }

        DB::transaction(function () use ($ledgers, $admin, $transaction) {
            $walletIds = $ledgers->pluck('wallet_id')->unique()->values()->all();
            sort($walletIds);

            $wallets = DB::table('wallets')
                ->whereIn('id', $walletIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            foreach ($ledgers as $ledger) {
                $wallet = $wallets->get($ledger->wallet_id);
                if (! $wallet) {
                    throw new \RuntimeException('Wallet not found for rollback.');
                }

                $amount = (float) $ledger->amount;
                $direction = $ledger->direction === 'credit' ? 'debit' : 'credit';

                $balance = (float) $wallet->balance;
                $available = (float) $wallet->available_balance;

                if ($direction === 'debit') {
                    if ($available < $amount) {
                        throw new \RuntimeException('Insufficient balance for rollback.');
                    }
                    $balance -= $amount;
                    $available -= $amount;
                } else {
                    $balance += $amount;
                    $available += $amount;
                }

                DB::table('wallets')->where('id', $wallet->id)->update([
                    'balance' => $balance,
                    'available_balance' => $available,
                    'updated_at' => now(),
                ]);

                $reversalId = $this->createLedger([
                    'atomic_transaction_id' => $transaction->id,
                    'wallet_id' => $wallet->id,
                    'related_wallet_id' => $ledger->related_wallet_id,
                    'direction' => $direction,
                    'amount' => $amount,
                    'currency' => $ledger->currency,
                    'status' => 'completed',
                    'type' => 'rollback',
                    'reference' => 'atomic_rollback',
                    'initiated_by' => $admin->id,
                    'approved_by' => $admin->id,
                    'audit_action' => 'atomic_rollback',
                ]);

                DB::table('transaction_ledger_audits')->insert([
                    'transaction_ledger_id' => $ledger->id,
                    'action' => 'reversed',
                    'actor_id' => $admin->id,
                    'payload' => json_encode(['reversal_ledger_id' => $reversalId]),
                    'created_at' => now(),
                ]);

                DB::table('transaction_ledgers')->where('id', $ledger->id)->update([
                    'status' => 'reversed',
                    'updated_at' => now(),
                ]);
            }

            DB::table('atomic_transactions')->where('id', $transaction->id)->update([
                'status' => 'reversed',
                'updated_at' => now(),
            ]);
        });

        return response()->json([
            'message' => 'Atomic transaction rolled back successfully',
            'atomic_transaction_id' => (int) $transaction->id,
        ], 200);
    }
}
