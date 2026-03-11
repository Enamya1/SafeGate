<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AdminFinanceController extends Controller
{
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

    private function walletStatusId(string $code): ?int
    {
        return DB::table('wallet_statuses')->where('code', $code)->value('id');
    }

    private function recordWalletStatusHistory(int $walletId, ?int $fromStatusId, int $toStatusId, int $adminId, ?string $reason): void
    {
        DB::table('wallet_status_histories')->insert([
            'wallet_id' => $walletId,
            'from_status_id' => $fromStatusId,
            'to_status_id' => $toStatusId,
            'changed_by' => $adminId,
            'reason' => $reason,
            'created_at' => now(),
        ]);
    }

    private function applyFreezeChange(int $walletId, int $adminId, string $action, ?string $reason): array
    {
        $activeId = $this->walletStatusId('active');
        $frozenId = $this->walletStatusId('frozen');

        if (! $activeId || ! $frozenId) {
            return ['error' => response()->json(['message' => 'Wallet status configuration missing.'], 500)];
        }

        $wallet = DB::table('wallets')->where('id', $walletId)->lockForUpdate()->first();

        if (! $wallet) {
            return ['error' => response()->json(['message' => 'Wallet not found.'], 404)];
        }

        $fromStatusId = (int) $wallet->status_id;
        $toStatusId = $action === 'freeze' ? $frozenId : $activeId;

        if ($fromStatusId === $toStatusId) {
            return ['error' => response()->json(['message' => 'Wallet already in requested status.'], 409)];
        }

        DB::table('wallets')->where('id', $wallet->id)->update([
            'status_id' => $toStatusId,
            'frozen_at' => $action === 'freeze' ? now() : null,
            'freeze_reason' => $action === 'freeze' ? $reason : null,
            'updated_at' => now(),
        ]);

        $this->recordWalletStatusHistory($wallet->id, $fromStatusId, $toStatusId, $adminId, $reason);

        return ['wallet_id' => $wallet->id];
    }

    public function freezeWallet(Request $request, string $walletId)
    {
        [$admin, $errorResponse] = $this->adminGuard($request);
        if ($errorResponse) {
            return $errorResponse;
        }

        try {
            $validated = $request->validate([
                'reason' => 'nullable|string|max:500',
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Validation Error',
                'errors' => $e->errors(),
            ], 422);
        }

        $result = DB::transaction(function () use ($admin, $walletId, $validated) {
            $change = $this->applyFreezeChange((int) $walletId, $admin->id, 'freeze', $validated['reason'] ?? null);
            if (isset($change['error'])) {
                return $change;
            }

            $requestId = DB::table('wallet_freeze_requests')->insertGetId([
                'wallet_id' => (int) $walletId,
                'requested_by' => $admin->id,
                'approved_by' => $admin->id,
                'action' => 'freeze',
                'status' => 'approved',
                'reason' => $validated['reason'] ?? null,
                'approved_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return ['request_id' => $requestId];
        });

        if (isset($result['error'])) {
            return $result['error'];
        }

        return response()->json([
            'message' => 'Wallet frozen successfully',
            'freeze_request_id' => $result['request_id'],
        ], 200);
    }

    public function unfreezeWallet(Request $request, string $walletId)
    {
        [$admin, $errorResponse] = $this->adminGuard($request);
        if ($errorResponse) {
            return $errorResponse;
        }

        try {
            $validated = $request->validate([
                'reason' => 'nullable|string|max:500',
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Validation Error',
                'errors' => $e->errors(),
            ], 422);
        }

        $result = DB::transaction(function () use ($admin, $walletId, $validated) {
            $change = $this->applyFreezeChange((int) $walletId, $admin->id, 'unfreeze', $validated['reason'] ?? null);
            if (isset($change['error'])) {
                return $change;
            }

            $requestId = DB::table('wallet_freeze_requests')->insertGetId([
                'wallet_id' => (int) $walletId,
                'requested_by' => $admin->id,
                'approved_by' => $admin->id,
                'action' => 'unfreeze',
                'status' => 'approved',
                'reason' => $validated['reason'] ?? null,
                'approved_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return ['request_id' => $requestId];
        });

        if (isset($result['error'])) {
            return $result['error'];
        }

        return response()->json([
            'message' => 'Wallet unfrozen successfully',
            'freeze_request_id' => $result['request_id'],
        ], 200);
    }

    public function listFreezeRequests(Request $request)
    {
        [$admin, $errorResponse] = $this->adminGuard($request);
        if ($errorResponse) {
            return $errorResponse;
        }

        $query = DB::table('wallet_freeze_requests')
            ->orderByDesc('created_at');

        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->toString());
        }

        if ($request->filled('action')) {
            $query->where('action', $request->string('action')->toString());
        }

        $limit = (int) $request->input('limit', 100);
        $limit = max(1, min(500, $limit));

        $requests = $query->limit($limit)->get();

        return response()->json([
            'message' => 'Freeze requests retrieved successfully',
            'requests' => $requests,
        ], 200);
    }

    public function approveFreezeRequest(Request $request, string $requestId)
    {
        [$admin, $errorResponse] = $this->adminGuard($request);
        if ($errorResponse) {
            return $errorResponse;
        }

        $result = DB::transaction(function () use ($admin, $requestId) {
            $freezeRequest = DB::table('wallet_freeze_requests')
                ->where('id', $requestId)
                ->lockForUpdate()
                ->first();

            if (! $freezeRequest) {
                return ['error' => response()->json(['message' => 'Freeze request not found.'], 404)];
            }

            if ($freezeRequest->status !== 'pending') {
                return ['error' => response()->json(['message' => 'Freeze request already processed.'], 409)];
            }

            $change = $this->applyFreezeChange((int) $freezeRequest->wallet_id, $admin->id, $freezeRequest->action, $freezeRequest->reason);
            if (isset($change['error'])) {
                return $change;
            }

            DB::table('wallet_freeze_requests')->where('id', $freezeRequest->id)->update([
                'approved_by' => $admin->id,
                'status' => 'approved',
                'approved_at' => now(),
                'updated_at' => now(),
            ]);

            return ['request_id' => $freezeRequest->id];
        });

        if (isset($result['error'])) {
            return $result['error'];
        }

        return response()->json([
            'message' => 'Freeze request approved successfully',
            'freeze_request_id' => $result['request_id'],
        ], 200);
    }

    private function applyLedgerReversal(object $ledger, object $admin, int $reversalId): int
    {
        $wallet = DB::table('wallets')->where('id', $ledger->wallet_id)->lockForUpdate()->first();

        if (! $wallet) {
            throw new \RuntimeException('Wallet not found for reversal.');
        }

        $amount = (float) $ledger->amount;
        $direction = $ledger->direction === 'credit' ? 'debit' : 'credit';
        $balance = (float) $wallet->balance;
        $available = (float) $wallet->available_balance;

        if ($direction === 'debit') {
            if ($available < $amount) {
                throw new \RuntimeException('Insufficient balance for reversal.');
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

        $reversalLedgerId = DB::table('transaction_ledgers')->insertGetId([
            'ledger_uuid' => (string) Str::uuid(),
            'atomic_transaction_id' => $ledger->atomic_transaction_id,
            'wallet_id' => $ledger->wallet_id,
            'related_wallet_id' => $ledger->related_wallet_id,
            'direction' => $direction,
            'amount' => $amount,
            'currency' => $ledger->currency,
            'status' => 'completed',
            'type' => 'reversal',
            'reference' => 'reversal_'.$ledger->id,
            'metadata' => json_encode(['reversal_id' => $reversalId]),
            'occurred_at' => now(),
            'initiated_by' => $admin->id,
            'approved_by' => $admin->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('transaction_ledger_audits')->insert([
            'transaction_ledger_id' => $ledger->id,
            'action' => 'reversed',
            'actor_id' => $admin->id,
            'payload' => json_encode(['reversal_ledger_id' => $reversalLedgerId]),
            'created_at' => now(),
        ]);

        DB::table('transaction_ledgers')->where('id', $ledger->id)->update([
            'status' => 'reversed',
            'updated_at' => now(),
        ]);

        return $reversalLedgerId;
    }

    public function reverseLedger(Request $request, string $ledgerId)
    {
        [$admin, $errorResponse] = $this->adminGuard($request);
        if ($errorResponse) {
            return $errorResponse;
        }

        try {
            $validated = $request->validate([
                'reason' => 'nullable|string|max:500',
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Validation Error',
                'errors' => $e->errors(),
            ], 422);
        }

        $ledger = DB::table('transaction_ledgers')->where('id', $ledgerId)->first();

        if (! $ledger) {
            return response()->json([
                'message' => 'Ledger entry not found.',
            ], 404);
        }

        if ($ledger->status !== 'completed') {
            return response()->json([
                'message' => 'Only completed ledger entries can be reversed.',
            ], 409);
        }

        $amount = (float) $ledger->amount;
        $requiredLevel = $amount >= 1000 ? 2 : 1;

        $reversalId = DB::table('transaction_reversals')->insertGetId([
            'transaction_ledger_id' => $ledger->id,
            'requested_by' => $admin->id,
            'approved_by_level1' => $requiredLevel >= 1 ? $admin->id : null,
            'approved_by_level2' => null,
            'status' => $requiredLevel === 1 ? 'approved' : 'pending',
            'reason' => $validated['reason'] ?? null,
            'amount' => $amount,
            'currency' => $ledger->currency,
            'approval_required_level' => $requiredLevel,
            'approved_level' => $requiredLevel === 1 ? 1 : 0,
            'reversal_ledger_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($requiredLevel === 1) {
            DB::transaction(function () use ($ledger, $admin, $reversalId) {
                $reversalLedgerId = $this->applyLedgerReversal($ledger, $admin, $reversalId);
                DB::table('transaction_reversals')->where('id', $reversalId)->update([
                    'status' => 'completed',
                    'approved_level' => 1,
                    'reversal_ledger_id' => $reversalLedgerId,
                    'updated_at' => now(),
                ]);
            });
        }

        return response()->json([
            'message' => 'Reversal request created successfully',
            'reversal_id' => $reversalId,
            'approval_required_level' => $requiredLevel,
        ], 201);
    }

    public function approveReversal(Request $request, string $reversalId)
    {
        [$admin, $errorResponse] = $this->adminGuard($request);
        if ($errorResponse) {
            return $errorResponse;
        }

        $result = DB::transaction(function () use ($admin, $reversalId) {
            $reversal = DB::table('transaction_reversals')
                ->where('id', $reversalId)
                ->lockForUpdate()
                ->first();

            if (! $reversal) {
                return ['error' => response()->json(['message' => 'Reversal request not found.'], 404)];
            }

            if ($reversal->status === 'completed') {
                return ['error' => response()->json(['message' => 'Reversal already completed.'], 409)];
            }

            $approvedLevel = (int) $reversal->approved_level;
            $requiredLevel = (int) $reversal->approval_required_level;

            if ($approvedLevel === 0) {
                DB::table('transaction_reversals')->where('id', $reversal->id)->update([
                    'approved_by_level1' => $admin->id,
                    'approved_level' => 1,
                    'status' => $requiredLevel === 1 ? 'approved' : 'pending',
                    'updated_at' => now(),
                ]);
                $approvedLevel = 1;
            } elseif ($approvedLevel === 1 && $requiredLevel >= 2) {
                DB::table('transaction_reversals')->where('id', $reversal->id)->update([
                    'approved_by_level2' => $admin->id,
                    'approved_level' => 2,
                    'status' => 'approved',
                    'updated_at' => now(),
                ]);
                $approvedLevel = 2;
            }

            if ($approvedLevel >= $requiredLevel) {
                $ledger = DB::table('transaction_ledgers')->where('id', $reversal->transaction_ledger_id)->first();
                if (! $ledger) {
                    return ['error' => response()->json(['message' => 'Ledger entry not found for reversal.'], 404)];
                }

                $reversalLedgerId = $this->applyLedgerReversal($ledger, $admin, $reversal->id);

                DB::table('transaction_reversals')->where('id', $reversal->id)->update([
                    'status' => 'completed',
                    'reversal_ledger_id' => $reversalLedgerId,
                    'updated_at' => now(),
                ]);
            }

            return ['reversal_id' => $reversal->id];
        });

        if (isset($result['error'])) {
            return $result['error'];
        }

        return response()->json([
            'message' => 'Reversal approval recorded successfully',
            'reversal_id' => $result['reversal_id'],
        ], 200);
    }

    public function listFraudAlerts(Request $request)
    {
        [$admin, $errorResponse] = $this->adminGuard($request);
        if ($errorResponse) {
            return $errorResponse;
        }

        $query = DB::table('fraud_alerts')->orderByDesc('created_at');

        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->toString());
        }

        if ($request->filled('risk_level')) {
            $query->where('risk_level', $request->string('risk_level')->toString());
        }

        $limit = (int) $request->input('limit', 100);
        $limit = max(1, min(500, $limit));

        $alerts = $query->limit($limit)->get();

        return response()->json([
            'message' => 'Fraud alerts retrieved successfully',
            'alerts' => $alerts,
        ], 200);
    }

    public function updateFraudAlert(Request $request, string $alertId)
    {
        [$admin, $errorResponse] = $this->adminGuard($request);
        if ($errorResponse) {
            return $errorResponse;
        }

        try {
            $validated = $request->validate([
                'status' => 'required|string|in:open,reviewing,resolved,ignored',
                'external_provider' => 'nullable|string|max:100',
                'external_reference' => 'nullable|string|max:150',
                'external_score' => 'nullable|numeric|min:0',
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Validation Error',
                'errors' => $e->errors(),
            ], 422);
        }

        $alert = DB::table('fraud_alerts')->where('id', $alertId)->first();

        if (! $alert) {
            return response()->json([
                'message' => 'Fraud alert not found.',
            ], 404);
        }

        DB::table('fraud_alerts')->where('id', $alert->id)->update([
            'status' => $validated['status'],
            'external_provider' => $validated['external_provider'] ?? $alert->external_provider,
            'external_reference' => $validated['external_reference'] ?? $alert->external_reference,
            'external_score' => $validated['external_score'] ?? $alert->external_score,
            'updated_at' => now(),
        ]);

        return response()->json([
            'message' => 'Fraud alert updated successfully',
            'alert_id' => (int) $alert->id,
        ], 200);
    }
}
