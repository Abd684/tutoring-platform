<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\SubscriptionPlan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SubscriptionPlanController extends ApiController
{
    public function available(Request $request): JsonResponse
    {
        $validatedData = $request->validate([
            'billing_cycle' => ['sometimes', Rule::in(['monthly', 'yearly'])],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $query = SubscriptionPlan::query()
            ->where('status', 'active')
            ->when(
                isset($validatedData['billing_cycle']),
                fn ($query) => $query->where('billing_cycle', $validatedData['billing_cycle'])
            )
            ->orderBy('price')
            ->orderBy('id');

        return $this->paginated($query->paginate($this->perPage($request)));
    }

    public function index(Request $request): JsonResponse
    {
        $query = SubscriptionPlan::query();

        $query->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('billing_cycle'), fn ($q) => $q->where('billing_cycle', $request->string('billing_cycle')))
            ->when($request->filled('search'), fn ($q) => $q->where('name', 'like', '%'.$request->string('search').'%'));

        return $this->paginated($query->orderBy('price')->orderBy('id')->paginate($this->perPage($request)));
    }

    public function store(Request $request): JsonResponse
    {
        $plan = SubscriptionPlan::create($request->validate([
            'name' => ['required', 'string', 'max:255'],
            'price' => ['required', 'numeric', 'min:0', 'max:9999999999.99'],
            'billing_cycle' => ['required', Rule::in(['monthly', 'yearly'])],
            'commission_rate' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'max_students' => ['nullable', 'integer', 'min:0'],
            'max_storage' => ['nullable', 'integer', 'min:0'],
            'max_ai_usage' => ['nullable', 'integer', 'min:0'],
            'max_group_size' => ['nullable', 'integer', 'min:1'],
            'status' => ['sometimes', 'string', 'max:50'],
        ]));

        return $this->success($plan, 'Subscription plan created successfully.', 201);
    }

    public function show(SubscriptionPlan $subscriptionPlan): JsonResponse
    {
        return $this->success($subscriptionPlan->loadCount('teacherSubscriptions'));
    }

    public function update(Request $request, SubscriptionPlan $subscriptionPlan): JsonResponse
    {
        $subscriptionPlan->update($request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'price' => ['sometimes', 'numeric', 'min:0', 'max:9999999999.99'],
            'billing_cycle' => ['sometimes', Rule::in(['monthly', 'yearly'])],
            'commission_rate' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'max_students' => ['nullable', 'integer', 'min:0'],
            'max_storage' => ['nullable', 'integer', 'min:0'],
            'max_ai_usage' => ['nullable', 'integer', 'min:0'],
            'max_group_size' => ['nullable', 'integer', 'min:1'],
            'status' => ['sometimes', 'string', 'max:50'],
        ]));

        return $this->success($subscriptionPlan->fresh(), 'Subscription plan updated successfully.');
    }

    public function destroy(SubscriptionPlan $subscriptionPlan): JsonResponse
    {
        if ($subscriptionPlan->teacherSubscriptions()->where('status', 'active')->exists()) {
            return $this->businessError('A plan with active teacher subscriptions cannot be deleted.', 409);
        }

        $subscriptionPlan->delete();

        return $this->success(message: 'Subscription plan deleted successfully.');
    }
}
