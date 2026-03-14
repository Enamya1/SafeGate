<?php

namespace App\Http\Controllers;

use App\Services\FraudDetectionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LedgerController extends Controller
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
            'status' => $payload['status'] ?? 'pending',
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
        [$admin, $errorResponse] = $this->adminGuard($request);
        if ($errorResponse) {
            return $errorResponse;
        }

        try {
            $validated = $request->validate([
                'wallet_id' => 'required|integer|exists:wallets,id',
                'direction' => 'required|string|in:credit,debit',
                'amount' => 'required|numeric|min:0.01',
                'currency' => 'required|string|size:3',
                'status' => 'nullable|string|in:pending,completed,failed,reversed',
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

        $amount = (float) $validated['amount'];
        $status = $validated['status'] ?? 'completed';
        $ledgerId = null;

        $result = DB::transaction(function () use ($validated, $admin, $amount, $status, &$ledgerId) {
            $wallet = DB::table('wallets')
                ->where('id', $validated['wallet_id'])
                ->lockForUpdate()
                ->first();

            if (! $wallet) {
                return ['error' => response()->json(['message' => 'Wallet not found.'], 404)];
            }

            if ($wallet->currency !== $validated['currency']) {
                return ['error' => response()->json(['message' => 'Wallet currency mismatch.'], 409)];
            }

            if ($status === 'completed') {
                $balance = (float) $wallet->balance;
                $available = (float) $wallet->available_balance;

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

                DB::table('wallets')->where('id', $wallet->id)->update([
                    'balance' => $balance,
                    'available_balance' => $available,
                    'updated_at' => now(),
                ]);
            }

            $ledgerId = $this->createLedger([
                'wallet_id' => $wallet->id,
                'direction' => $validated['direction'],
                'amount' => $amount,
                'currency' => $validated['currency'],
                'status' => $status,
                'type' => $validated['type'] ?? 'manual',
                'reference' => $validated['reference'] ?? null,
                'metadata' => array_key_exists('metadata', $validated) ? json_encode($validated['metadata']) : null,
                'initiated_by' => $admin->id,
                'approved_by' => $admin->id,
                'audit_action' => 'admin_create',
            ]);

            return ['ledger_id' => $ledgerId];
        });

        if (isset($result['error'])) {
            return $result['error'];
        }

        $ledger = DB::table('transaction_ledgers')->where('id', $ledgerId)->first();

        return response()->json([
            'message' => 'Ledger entry created successfully',
            'ledger' => $ledger,
        ], 201);
    }

    public function show(Request $request, string $ledgerId)
    {
        [$admin, $errorResponse] = $this->adminGuard($request);
        if ($errorResponse) {
            return $errorResponse;
        }

        $ledger = DB::table('transaction_ledgers')->where('id', $ledgerId)->first();

        if (! $ledger) {
            return response()->json([
                'message' => 'Ledger entry not found.',
            ], 404);
        }

        $audits = DB::table('transaction_ledger_audits')
            ->where('transaction_ledger_id', $ledger->id)
            ->orderBy('created_at')
            ->get();

        return response()->json([
            'message' => 'Ledger entry retrieved successfully',
            'ledger' => $ledger,
            'audits' => $audits,
        ], 200);
    }

    public function search(Request $request)
    {
        [$admin, $errorResponse] = $this->adminGuard($request);
        if ($errorResponse) {
            return $errorResponse;
        }

        $query = DB::table('transaction_ledgers')->orderByDesc('occurred_at');

        if ($request->filled('wallet_id')) {
            $query->where('wallet_id', (int) $request->input('wallet_id'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->toString());
        }

        if ($request->filled('direction')) {
            $query->where('direction', $request->string('direction')->toString());
        }

        if ($request->filled('currency')) {
            $query->where('currency', strtoupper($request->string('currency')->toString()));
        }

        if ($request->filled('from')) {
            $query->where('occurred_at', '>=', $request->input('from'));
        }

        if ($request->filled('to')) {
            $query->where('occurred_at', '<=', $request->input('to'));
        }

        if ($request->filled('min_amount')) {
            $query->where('amount', '>=', (float) $request->input('min_amount'));
        }

        if ($request->filled('max_amount')) {
            $query->where('amount', '<=', (float) $request->input('max_amount'));
        }

        $limit = (int) $request->input('limit', 100);
        $limit = max(1, min(500, $limit));

        $ledgers = $query->limit($limit)->get();

        return response()->json([
            'message' => 'Ledger entries retrieved successfully',
            'count' => $ledgers->count(),
            'ledgers' => $ledgers,
        ], 200);
    }

    public function reconcile(Request $request)
    {
        [$admin, $errorResponse] = $this->adminGuard($request);
        if ($errorResponse) {
            return $errorResponse;
        }

        try {
            $validated = $request->validate([
                'wallet_id' => 'required|integer|exists:wallets,id',
                'from' => 'nullable|date',
                'to' => 'nullable|date',
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Validation Error',
                'errors' => $e->errors(),
            ], 422);
        }

        $wallet = DB::table('wallets')->where('id', $validated['wallet_id'])->first();

        if (! $wallet) {
            return response()->json([
                'message' => 'Wallet not found.',
            ], 404);
        }

        $query = DB::table('transaction_ledgers')
            ->where('wallet_id', $wallet->id)
            ->where('status', 'completed');

        if (! empty($validated['from'])) {
            $query->where('occurred_at', '>=', $validated['from']);
        }

        if (! empty($validated['to'])) {
            $query->where('occurred_at', '<=', $validated['to']);
        }

        $credits = (float) $query->clone()->where('direction', 'credit')->sum('amount');
        $debits = (float) $query->clone()->where('direction', 'debit')->sum('amount');

        $calculatedBalance = $credits - $debits;
        $walletBalance = (float) $wallet->balance;
        $delta = $walletBalance - $calculatedBalance;

        return response()->json([
            'message' => 'Ledger reconciliation completed',
            'wallet_id' => (int) $wallet->id,
            'wallet_balance' => $walletBalance,
            'ledger_balance' => $calculatedBalance,
            'delta' => $delta,
            'credits' => $credits,
            'debits' => $debits,
        ], 200);
    }
}
