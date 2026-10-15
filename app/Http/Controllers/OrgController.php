<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Brand;
use App\Models\Company;
use App\Models\Outlet;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Master organisasi: Company -> Brand -> Outlet.
 *
 * MEMBACA pohon ini boleh siapa saja yang login (dipakai untuk mengisi dropdown
 * filter), tapi hanya cabang yang menyentuh scope-nya yang dikirim — kalau tidak,
 * nama outlet perusahaan lain ikut bocor lewat dropdown.
 *
 * MENGUBAHNYA hanya admin (permission org.manage): memindahkan outlet antar brand
 * berarti memindahkan hak akses orang lain, jadi ini bukan wewenang manajer.
 */
class OrgController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        // Brand & outlet bertingkat ikut disaring, bukan cuma company-nya.
        return Company::visibleTo($user)
            ->with([
                'brands' => fn ($q) => $q->visibleTo($user)->orderBy('name'),
                'brands.outlets' => fn ($q) => $q->visibleTo($user)->orderBy('name'),
            ])
            ->orderBy('name')
            ->get();
    }

    // --- Companies ---

    public function storeCompany(Request $request)
    {
        $this->authorizePermission($request, 'org.manage');

        return Company::create($request->validate([
            'name' => 'required|unique:companies,name',
            'is_active' => 'boolean',
        ]));
    }

    public function updateCompany(Request $request, string $id)
    {
        $this->authorizePermission($request, 'org.manage');

        $company = Company::findOrFail($id);
        $company->update($request->validate([
            'name' => ['required', Rule::unique('companies', 'name')->ignore($id)],
            'is_active' => 'boolean',
        ]));

        return $company;
    }

    public function destroyCompany(Request $request, string $id)
    {
        $this->authorizePermission($request, 'org.manage');

        // cascadeOnDelete: brand & outlet di bawahnya ikut terhapus; penugasan
        // user ke sub-pohon ini ikut dibersihkan (lihat Company::booted).
        $company = Company::findOrFail($id);
        AuditLog::record('org.company_deleted', $company);
        $company->delete();

        return response()->json(['success' => true]);
    }

    // --- Brands ---

    public function storeBrand(Request $request)
    {
        $this->authorizePermission($request, 'org.manage');

        return Brand::create($request->validate([
            'company_id' => 'required|exists:companies,id',
            'name' => [
                'required',
                Rule::unique('brands', 'name')->where(fn ($q) => $q->where('company_id', $request->company_id)),
            ],
            'is_active' => 'boolean',
        ], [
            'name.unique' => 'Brand dengan nama ini sudah ada di company tersebut.',
        ]));
    }

    public function updateBrand(Request $request, string $id)
    {
        $this->authorizePermission($request, 'org.manage');

        $brand = Brand::findOrFail($id);
        $brand->update($request->validate([
            'company_id' => 'required|exists:companies,id',
            'name' => [
                'required',
                Rule::unique('brands', 'name')
                    ->where(fn ($q) => $q->where('company_id', $request->company_id))
                    ->ignore($id),
            ],
            'is_active' => 'boolean',
        ]));

        return $brand;
    }

    public function destroyBrand(Request $request, string $id)
    {
        $this->authorizePermission($request, 'org.manage');

        $brand = Brand::findOrFail($id);
        AuditLog::record('org.brand_deleted', $brand);
        $brand->delete();

        return response()->json(['success' => true]);
    }

    // --- Outlets ---

    /**
     * Titik koordinat outlet untuk geofence absen manual pakai foto. Opsional,
     * tapi harus diisi berpasangan — satu tanpa yang lain tak berguna.
     */
    private const COORDINATE_RULES = [
        'latitude' => 'nullable|numeric|between:-90,90|required_with:longitude',
        'longitude' => 'nullable|numeric|between:-180,180|required_with:latitude',
    ];

    private const COORDINATE_MESSAGES = [
        'latitude.required_with' => 'Latitude & longitude harus diisi berpasangan.',
        'longitude.required_with' => 'Latitude & longitude harus diisi berpasangan.',
    ];

    public function storeOutlet(Request $request)
    {
        $this->authorizePermission($request, 'org.manage');

        return Outlet::create($request->validate([
            'brand_id' => 'required|exists:brands,id',
            'name' => [
                'required',
                Rule::unique('outlets', 'name')->where(fn ($q) => $q->where('brand_id', $request->brand_id)),
            ],
            'is_active' => 'boolean',
            ...self::COORDINATE_RULES,
        ], [
            'name.unique' => 'Outlet dengan nama ini sudah ada di brand tersebut.',
            ...self::COORDINATE_MESSAGES,
        ]));
    }

    public function updateOutlet(Request $request, string $id)
    {
        $this->authorizePermission($request, 'org.manage');

        $outlet = Outlet::findOrFail($id);
        $outlet->update($request->validate([
            'brand_id' => 'required|exists:brands,id',
            'name' => [
                'required',
                Rule::unique('outlets', 'name')
                    ->where(fn ($q) => $q->where('brand_id', $request->brand_id))
                    ->ignore($id),
            ],
            'is_active' => 'boolean',
            ...self::COORDINATE_RULES,
        ], self::COORDINATE_MESSAGES));

        return $outlet;
    }

    public function destroyOutlet(Request $request, string $id)
    {
        $this->authorizePermission($request, 'org.manage');

        $outlet = Outlet::findOrFail($id);
        AuditLog::record('org.outlet_deleted', $outlet);
        $outlet->delete();

        return response()->json(['success' => true]);
    }
}
