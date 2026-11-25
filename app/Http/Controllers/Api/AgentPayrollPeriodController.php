<?php

namespace App\Http\Controllers\Api;

use App\Models\PayrollPeriod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

final class AgentPayrollPeriodController
{
    private const FIELDS = ['id', 'starts_on', 'ends_on', 'status'];

    public function index(Request $request): JsonResponse
    {
        $input = Validator::make(['query' => $request->query()], [
            'query' => ['array:page,next_draft'],
            'query.page' => ['sometimes', 'required', 'regex:/\A[1-9][0-9]{0,2}\z/'],
            'query.next_draft' => ['sometimes', 'required', 'in:0,1'],
        ])->validate();

        $page = (int) ($input['query']['page'] ?? 1);
        $periods = PayrollPeriod::query();
        if (($input['query']['next_draft'] ?? null) === '1') {
            $today = today('Asia/Manila')->toDateString();
            $periods->where('status', 'draft')->whereDate('ends_on', '>=', $today)->orderBy('starts_on')->orderBy('id');
        } else {
            $periods->orderByDesc('starts_on')->orderByDesc('id');
        }
        $periods = $periods->simplePaginate(10, self::FIELDS, 'page', $page);

        return response()->json([
            'records' => $periods->getCollection()->map($this->record(...))->values()->all(),
            'page' => $page,
            'has_more' => $periods->hasMorePages(),
        ]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $key = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        abort_if($request->query() !== [] || ! preg_match('/\A[1-9][0-9]*\z/', $id) || $key === false, 422);
        $period = PayrollPeriod::query()->findOrFail($key, self::FIELDS);

        return response()->json(['record' => $this->record($period)]);
    }

    private function record(PayrollPeriod $period): array
    {
        return [
            'id' => (string) $period->getKey(),
            'starts_on' => $period->starts_on->toDateString(),
            'ends_on' => $period->ends_on->toDateString(),
            'status' => $period->status,
        ];
    }
}
