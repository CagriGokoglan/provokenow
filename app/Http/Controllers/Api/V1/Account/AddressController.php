<?php

namespace App\Http\Controllers\Api\V1\Account;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Models\UserAddress;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Account → Settings → address book. Always scoped to the signed-in user. */
class AddressController extends Controller
{
    public const MAX_ADDRESSES = 10;

    private const RULES = [
        'label' => ['nullable', 'string', 'max:40'],
        'name' => ['required', 'string', 'max:255'],
        'line1' => ['required', 'string', 'max:255'],
        'line2' => ['nullable', 'string', 'max:255'],
        'city' => ['required', 'string', 'max:120'],
        'state' => ['nullable', 'string', 'max:120'],
        'postal_code' => ['required', 'string', 'max:20'],
        'country' => ['required', 'string', 'size:2'],
        'phone' => ['nullable', 'string', 'max:40'],
        'is_default' => ['sometimes', 'boolean'],
    ];

    public function index(Request $request): JsonResponse
    {
        return ApiResponse::success(['addresses' => $request->user()->addresses()->get()->map->only($this->fields())], 'Addresses.');
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(self::RULES);
        $user = $request->user();

        if ($user->addresses()->count() >= self::MAX_ADDRESSES) {
            throw new BusinessRuleException('You can save up to '.self::MAX_ADDRESSES.' addresses.', 422);
        }

        $address = DB::transaction(function () use ($user, $data) {
            // The first address becomes the default automatically.
            $data['is_default'] = ($data['is_default'] ?? false) || ! $user->addresses()->exists();
            $address = $user->addresses()->create(['country' => strtoupper($data['country'])] + $data);
            $this->keepSingleDefault($address);

            return $address;
        });

        return ApiResponse::success(['address' => $address->only($this->fields())], 'Address saved.', 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $address = $this->find($request, $id);
        $rules = array_map(fn ($r) => array_map(fn ($x) => $x === 'required' ? 'sometimes' : $x, $r), self::RULES);
        $data = $request->validate($rules);
        if (isset($data['country'])) {
            $data['country'] = strtoupper($data['country']);
        }

        DB::transaction(function () use ($address, $data) {
            $address->update($data);
            $this->keepSingleDefault($address);
        });

        return ApiResponse::success(['address' => $address->fresh()->only($this->fields())], 'Address updated.');
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $address = $this->find($request, $id);

        DB::transaction(function () use ($address, $request) {
            $wasDefault = $address->is_default;
            $address->delete();
            if ($wasDefault) {
                $request->user()->addresses()->first()?->update(['is_default' => true]);
            }
        });

        return ApiResponse::success(null, 'Address removed.');
    }

    private function find(Request $request, string $id): UserAddress
    {
        return $request->user()->addresses()->whereKey($id)->firstOrFail();
    }

    private function keepSingleDefault(UserAddress $address): void
    {
        if ($address->is_default) {
            UserAddress::where('user_id', $address->user_id)->whereKeyNot($address->getKey())->update(['is_default' => false]);
        }
    }

    /** @return list<string> */
    private function fields(): array
    {
        return ['id', 'label', 'name', 'line1', 'line2', 'city', 'state', 'postal_code', 'country', 'phone', 'is_default'];
    }
}
