<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AttendanceItemMaster;
use App\Models\BusinessLocation;
use App\Models\ClosingDateGroup;
use App\Models\Department;
use App\Models\EmployeeCommuteRoute;
use App\Models\EmployeePayItemValue;
use App\Models\JobTitle;
use App\Models\LeaveType;
use App\Models\PayItemMaster;
use App\Models\ResidentTaxMunicipality;
use App\Models\Setting;
use App\Models\User;
use App\Models\UserStatusHistory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;

class UserController extends Controller
{
    public function index(Request $request)
    {
        $filters = [
            'search' => trim((string) $request->input('search', '')),
            'business_location_id' => $request->input('business_location_id', ''),
            'department_id' => $request->input('department_id', ''),
            'job_title_id' => $request->input('job_title_id', ''),
            'employment_type' => $request->input('employment_type', ''),
            'status' => $request->input('status', ''),
        ];

        $query = User::with(['department', 'employeePayroll.businessLocation', 'employeePayroll.jobTitle']);

        if ($filters['search'] !== '') {
            $s = $filters['search'];
            $query->where(function ($q) use ($s) {
                $q->where('name', 'like', "%{$s}%")
                    ->orWhere('last_name', 'like', "%{$s}%")
                    ->orWhere('first_name', 'like', "%{$s}%")
                    ->orWhere('last_name_kana', 'like', "%{$s}%")
                    ->orWhere('first_name_kana', 'like', "%{$s}%")
                    ->orWhere('customer_no', 'like', "%{$s}%")
                    ->orWhereHas('employeePayroll', fn ($qq) => $qq->where('employee_no', 'like', "%{$s}%"));
            });
        }

        if ($filters['department_id'] !== '') {
            $query->where('department_id', $filters['department_id']);
        }

        foreach (['business_location_id', 'job_title_id', 'employment_type'] as $epFilter) {
            if ($filters[$epFilter] !== '') {
                $query->whereHas('employeePayroll', fn ($q) => $q->where($epFilter, $filters[$epFilter]));
            }
        }

        $this->applySort($query);

        $users = $query->get()
            ->filter(function (User $user) use ($filters) {
                $status = $filters['status'];
                // 初期表示（未指定）は退職者を除外。'all' で退職者も含めて全件表示。
                if ($status === '') {
                    return $user->employment_status !== 'retired';
                }
                if ($status === 'all') {
                    return true;
                }
                return $user->employment_status === $status;
            })
            ->map(function (User $user) {
                $ep = $user->employeePayroll;
                return [
                    'id' => $user->id,
                    'employee_no' => $ep?->employee_no,
                    'full_name' => $user->full_name,
                    'name' => $user->name,
                    'employment_status' => $user->employment_status,
                    'department' => $user->department?->only(['id', 'name']),
                    'business_location' => $ep?->businessLocation?->only(['id', 'name']),
                    'job_title' => $ep?->jobTitle?->only(['id', 'name']),
                    'employment_type' => $ep?->employment_type,
                    'employment_type_label' => $ep ? ($this->employmentTypeLabels()[$ep->employment_type] ?? $ep->employment_type) : null,
                    'pay_type' => $ep?->pay_type,
                    'pay_type_label' => $ep ? ($this->payTypeLabels()[$ep->pay_type] ?? null) : null,
                ];
            })
            ->values();

        return Inertia::render('Admin/Users/Index', [
            'users' => $users,
            'filters' => $filters,
            'filterOptions' => [
                'departments' => Department::orderBy('sort_order')->get(['id', 'name']),
                'businessLocations' => BusinessLocation::orderBy('sort_order')->orderBy('id')->get(['id', 'name']),
                'jobTitles' => JobTitle::orderBy('sort_order')->get(['id', 'name']),
                'employmentTypes' => $this->employmentTypeLabels(),
                'statuses' => ['active' => '在籍中', 'pre_join' => '入社前', 'retired' => '退職', 'all' => 'すべて（退職含む）'],
            ],
        ]);
    }

    public function create()
    {
        return Inertia::render('Admin/Users/Create', [
            'options' => [
                'departments' => Department::orderBy('sort_order')->get(['id', 'name']),
                'businessLocations' => BusinessLocation::orderBy('sort_order')->orderBy('id')->get(['id', 'name']),
                'closingDateGroups' => ClosingDateGroup::orderBy('sort_order')->get(['id', 'name']),
                'employmentTypes' => $this->employmentTypeLabels(),
                'genders' => $this->genderLabels(),
            ],
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'last_name' => ['required', 'string', 'max:255'],
            'first_name' => ['nullable', 'string', 'max:255'],
            'last_name_kana' => ['nullable', 'string', 'max:255'],
            'first_name_kana' => ['nullable', 'string', 'max:255'],
            'gender' => ['nullable', 'in:male,female,other'],
            'employee_no' => ['nullable', 'string', 'max:50'],
            'joined_at' => ['nullable', 'date'],
            'employment_type' => ['required', 'string', 'max:30'],
            'department_id' => ['nullable', 'exists:departments,id'],
            'business_location_id' => ['nullable', 'exists:business_locations,id'],
            'closing_date_group_id' => ['nullable', 'exists:closing_date_groups,id'],
            'email' => ['nullable', 'email', 'max:255', 'unique:users,email'],
        ]);

        $user = DB::transaction(function () use ($validated) {
            $user = User::create([
                'last_name' => $validated['last_name'],
                'first_name' => $validated['first_name'] ?? null,
                'last_name_kana' => $validated['last_name_kana'] ?? null,
                'first_name_kana' => $validated['first_name_kana'] ?? null,
                'gender' => $validated['gender'] ?? null,
                'joined_at' => $validated['joined_at'] ?? null,
                'department_id' => $validated['department_id'] ?? null,
                'email' => $validated['email'] ?? null,
                'role' => 1,
                'is_active' => true,
                'password' => Str::password(32),
            ]);

            $user->employeePayroll()->create([
                'employee_no' => $validated['employee_no'] ?? null,
                'employment_type' => $validated['employment_type'],
                'business_location_id' => $validated['business_location_id'] ?? null,
                'closing_date_group_id' => $validated['closing_date_group_id'] ?? null,
            ]);

            UserStatusHistory::create([
                'user_id' => $user->id,
                'changed_by' => Auth::guard('admin')->id(),
                'from_status' => 'none',
                'to_status' => $user->employment_status,
                'note' => '従業員登録',
            ]);

            return $user;
        });

        return redirect()->route('admin.users.show', $user->id)->with('success', '従業員を登録しました');
    }

    /** 従業員詳細（タブ式：一般情報 / 給与情報 / 従業員メモ） */
    public function show(User $user)
    {
        $user->load([
            'department',
            'employeePayroll.businessLocation',
            'employeePayroll.jobTitle',
            'employeePayroll.closingDateGroup',
            'dependents',
            'leaves.leaveType',
        ]);

        $ep = $user->employeePayroll;
        $payType = $ep->pay_type ?? 'monthly';

        $payItems = PayItemMaster::active()
            ->forPayType($payType)
            ->orderBy('sort_order')
            ->get(['id', 'code', 'name', 'category', 'calc_method', 'sign', 'is_allowance_base', 'is_deduction_base'])
            ->map(fn ($m) => [
                'id' => $m->id,
                'code' => $m->code,
                'name' => $m->name,
                'category' => $m->category,
                'calc_method' => $m->calc_method,
                'sign' => $m->sign,
                'is_allowance_base' => (bool) $m->is_allowance_base,
                'is_deduction_base' => (bool) $m->is_deduction_base,
                'calc_method_label' => $this->payCalcMethodLabel($m->calc_method),
            ]);

        $payItemValues = EmployeePayItemValue::where('user_id', $user->id)
            ->pluck('amount', 'pay_item_master_id')
            ->map(fn ($v) => (int) $v);

        $commuteRoutes = $user->commuteRoutes()->get()->map(fn ($r) => [
            'id' => $r->id,
            'sort_order' => $r->sort_order,
            'transport_type' => $r->transport_type,
            'from_place' => $r->from_place,
            'to_place' => $r->to_place,
            'one_way_distance_km' => (float) $r->one_way_distance_km,
            'condition' => $r->condition,
            'payment_months' => $r->payment_months ?? [],
            'attendance_item_code' => $r->attendance_item_code,
            'amount' => (int) $r->amount,
            'payment_method' => $r->payment_method,
            'cap_amount' => $r->cap_amount,
            'non_taxable_limit' => $r->non_taxable_limit,
            'uses_parking' => (bool) $r->uses_parking,
            'parking_condition' => $r->parking_condition,
            'parking_payment_months' => $r->parking_payment_months ?? [],
            'parking_attendance_item_code' => $r->parking_attendance_item_code,
            'parking_amount' => (int) $r->parking_amount,
            'parking_payment_method' => $r->parking_payment_method,
            'parking_cap_amount' => $r->parking_cap_amount,
        ]);

        $attendanceItems = AttendanceItemMaster::active()
            ->orderBy('sort_order')
            ->get(['code', 'name'])
            ->map(fn ($a) => ['code' => $a->code, 'name' => $a->name]);

        $histories = $user->statusHistories()
            ->with('changedBy:id,name')
            ->limit(20)
            ->get()
            ->map(fn ($h) => [
                'id' => $h->id,
                'from_status' => $h->from_status,
                'to_status' => $h->to_status,
                'from_label' => UserStatusHistory::statusLabel($h->from_status),
                'to_label' => UserStatusHistory::statusLabel($h->to_status),
                'changed_by' => $h->changedBy?->name,
                'note' => $h->note,
                'changed_at' => $h->changed_at?->format('Y-m-d H:i'),
            ]);

        return Inertia::render('Admin/Users/Show', [
            'user' => array_merge($user->toArray(), [
                'full_name' => $user->full_name,
                'employment_status' => $user->employment_status,
                'my_number' => $user->my_number, // 復号済み
            ]),
            'payroll' => $ep ? array_merge($ep->toArray(), []) : $this->defaultPayroll(),
            'dependents' => $user->dependents->map(function ($d) {
                return array_merge($d->toArray(), ['my_number' => $d->my_number]);
            }),
            'leaves' => $user->leaves->map(fn ($l) => [
                'id' => $l->id,
                'leave_type_id' => $l->leave_type_id,
                'leave_type_name' => $l->leaveType?->name,
                'start_date' => $l->start_date?->toDateString(),
                'end_date' => $l->end_date?->toDateString(),
                'note' => $l->note,
            ]),
            'histories' => $histories,
            'payItems' => $payItems,
            'payItemValues' => $payItemValues,
            'commuteRoutes' => $commuteRoutes,
            'attendanceItems' => $attendanceItems,
            'options' => $this->detailOptions(),
        ]);
    }

    /**
     * セクション単位の更新。詳細ページの各「編集」ボタンから呼ばれる。
     */
    public function updateSection(Request $request, User $user, string $section)
    {
        switch ($section) {
            case 'basic':
                $this->updateBasic($request, $user);
                break;
            case 'employment':
                $this->updateEmployment($request, $user);
                break;
            case 'work':
                $this->updateWork($request, $user);
                break;
            case 'income_tax':
                $this->updateIncomeTax($request, $user);
                break;
            case 'resident_tax':
                $this->updateResidentTax($request, $user);
                break;
            case 'dependents':
                $this->updateDependents($request, $user);
                break;
            case 'leaves':
                $this->updateLeaves($request, $user);
                break;
            case 'note':
                $this->updateNote($request, $user);
                break;
            case 'salary_items':
                $this->updateSalaryItems($request, $user);
                break;
            case 'commute':
                $this->updateCommute($request, $user);
                break;
            default:
                abort(404);
        }

        return back()->with('success', '更新しました');
    }

    private function updateBasic(Request $request, User $user): void
    {
        $data = $request->validate([
            'last_name' => ['required', 'string', 'max:255'],
            'first_name' => ['nullable', 'string', 'max:255'],
            'last_name_kana' => ['nullable', 'string', 'max:255'],
            'first_name_kana' => ['nullable', 'string', 'max:255'],
            'gender' => ['nullable', 'in:male,female,other'],
            'birth_date' => ['nullable', 'date'],
            'email' => ['nullable', 'email', 'max:255', 'unique:users,email,'.$user->id],
            'phone' => ['nullable', 'string', 'max:20'],
            'postal_code' => ['nullable', 'string', 'max:10'],
            'prefecture' => ['nullable', 'string', 'max:20'],
            'city' => ['nullable', 'string', 'max:255'],
            'street' => ['nullable', 'string', 'max:255'],
            'building' => ['nullable', 'string', 'max:255'],
            'address_kana' => ['nullable', 'string', 'max:500'],
            'my_number' => ['nullable', 'string', 'max:20'],
            'emergency_contact_name' => ['nullable', 'string', 'max:255'],
            'emergency_contact_phone' => ['nullable', 'string', 'max:20'],
        ]);

        // 住所（合成: 後方互換の address カラム）
        $data['address'] = trim(implode('', array_filter([
            $data['prefecture'] ?? null,
            $data['city'] ?? null,
            $data['street'] ?? null,
            $data['building'] ?? null,
        ])));

        $user->update($data);
    }

    /**
     * 在籍情報（入社日・退職情報・在籍状態）。MF em05 の「在籍情報」に対応。
     */
    private function updateEmployment(Request $request, User $user): void
    {
        $data = $request->validate([
            'joined_at' => ['nullable', 'date'],
            'is_active' => ['boolean'],
            'customer_no' => ['nullable', 'string', 'max:50'],
            'retirement_date' => ['nullable', 'date'],
            'retirement_type' => ['nullable', 'string', 'max:50'],
            'retirement_reason' => ['nullable', 'string', 'max:255'],
        ]);

        $fromStatus = $user->employment_status;

        $user->update([
            'joined_at' => $data['joined_at'] ?? null,
            'is_active' => $data['is_active'] ?? $user->is_active,
            'customer_no' => $data['customer_no'] ?? null,
            'retirement_date' => $data['retirement_date'] ?? null,
            'retirement_type' => $data['retirement_type'] ?? null,
            'retirement_reason' => $data['retirement_reason'] ?? null,
        ]);

        $toStatus = $user->fresh()->employment_status;
        if ($fromStatus !== $toStatus) {
            UserStatusHistory::create([
                'user_id' => $user->id,
                'changed_by' => Auth::guard('admin')->id(),
                'from_status' => $fromStatus,
                'to_status' => $toStatus,
            ]);
        }
    }

    /**
     * 業務情報。MF em05 の「業務情報」に対応:
     * 従業員番号 / 契約種別 / 給与区分 / 締め日グループ / 所属事業所 / 部門 / 職種 / 役職 / 所定労働時間・日数。
     */
    private function updateWork(Request $request, User $user): void
    {
        $data = $request->validate([
            'employee_no' => ['nullable', 'string', 'max:50'],
            'employment_type' => ['required', 'string', 'max:30'],
            'pay_type' => ['required', 'in:monthly,hourly,daily'],
            'closing_date_group_id' => ['nullable', 'exists:closing_date_groups,id'],
            'business_location_id' => ['nullable', 'exists:business_locations,id'],
            'department_id' => ['nullable', 'exists:departments,id'],
            'job_title_id' => ['nullable', 'exists:job_titles,id'],
            'position' => ['nullable', 'string', 'max:255'],
            'work_hours_per_day' => ['nullable', 'numeric', 'min:0', 'max:24'],
            'work_days_monthly_avg' => ['nullable', 'numeric', 'min:0', 'max:31'],
            'work_hours_monthly_avg' => ['nullable', 'numeric', 'min:0', 'max:744'],
        ]);

        // 部門は users テーブル、それ以外は employee_payrolls テーブル。
        $user->update(['department_id' => $data['department_id'] ?? null]);

        $this->savePayroll($user, [
            'employee_no' => $data['employee_no'] ?? null,
            'employment_type' => $data['employment_type'],
            'pay_type' => $data['pay_type'],
            'closing_date_group_id' => $data['closing_date_group_id'] ?? null,
            'business_location_id' => $data['business_location_id'] ?? null,
            'job_title_id' => $data['job_title_id'] ?? null,
            'position' => $data['position'] ?? null,
            'work_hours_per_day' => $data['work_hours_per_day'] ?? null,
            'work_days_monthly_avg' => $data['work_days_monthly_avg'] ?? null,
            'work_hours_monthly_avg' => $data['work_hours_monthly_avg'] ?? null,
        ]);
    }

    private function updateIncomeTax(Request $request, User $user): void
    {
        $data = $request->validate([
            'tax_table' => ['required', 'in:kou,otsu'],
            'dependents_count' => ['required', 'integer', 'min:0', 'max:20'],
            'is_widow' => ['boolean'],
            'is_single_parent' => ['boolean'],
            'disability_type' => ['required', 'in:none,general,special'],
            'is_working_student' => ['boolean'],
            'is_minor' => ['boolean'],
            'is_disaster' => ['boolean'],
            'is_foreigner' => ['boolean'],
            'residency_type' => ['required', 'in:resident,non_resident'],
        ]);

        $this->savePayroll($user, $data);
    }

    private function updateResidentTax(Request $request, User $user): void
    {
        $data = $request->validate([
            'resident_tax_municipality' => ['nullable', 'string', 'max:255'],
            'resident_tax_recipient_number' => ['nullable', 'string', 'max:255'],
            'report_municipality' => ['nullable', 'string', 'max:255'],
        ]);

        $this->savePayroll($user, $data);

        ResidentTaxMunicipality::sync($data['resident_tax_municipality'] ?? null);
    }

    private function updateDependents(Request $request, User $user): void
    {
        $data = $request->validate([
            'dependents' => ['array'],
            'dependents.*.id' => ['nullable', 'integer'],
            'dependents.*.last_name' => ['nullable', 'string', 'max:255'],
            'dependents.*.first_name' => ['nullable', 'string', 'max:255'],
            'dependents.*.last_name_kana' => ['nullable', 'string', 'max:255'],
            'dependents.*.first_name_kana' => ['nullable', 'string', 'max:255'],
            'dependents.*.birth_date' => ['nullable', 'date'],
            'dependents.*.relationship' => ['nullable', 'string', 'max:50'],
            'dependents.*.my_number' => ['nullable', 'string', 'max:20'],
            'dependents.*.lives_together' => ['boolean'],
            'dependents.*.is_income_tax_dependent' => ['boolean'],
            'dependents.*.dependent_type' => ['nullable', 'string', 'max:30'],
            'dependents.*.is_same_livelihood_spouse' => ['boolean'],
            'dependents.*.disability_type' => ['nullable', 'in:none,general,special'],
            'dependents.*.is_health_insurance_dependent' => ['boolean'],
            'dependents.*.annual_income' => ['nullable', 'integer', 'min:0'],
        ]);

        $rows = $data['dependents'] ?? [];
        $keepIds = [];

        foreach ($rows as $i => $row) {
            $payload = [
                'last_name' => $row['last_name'] ?? null,
                'first_name' => $row['first_name'] ?? null,
                'last_name_kana' => $row['last_name_kana'] ?? null,
                'first_name_kana' => $row['first_name_kana'] ?? null,
                'birth_date' => $row['birth_date'] ?? null,
                'relationship' => $row['relationship'] ?? null,
                'my_number' => $row['my_number'] ?? null,
                'lives_together' => $row['lives_together'] ?? true,
                'is_income_tax_dependent' => $row['is_income_tax_dependent'] ?? false,
                'dependent_type' => $row['dependent_type'] ?? 'general',
                'is_same_livelihood_spouse' => $row['is_same_livelihood_spouse'] ?? false,
                'disability_type' => $row['disability_type'] ?? 'none',
                'is_health_insurance_dependent' => $row['is_health_insurance_dependent'] ?? false,
                'annual_income' => $row['annual_income'] ?? null,
                'sort_order' => $i,
            ];

            if (! empty($row['id'])) {
                $dependent = $user->dependents()->whereKey($row['id'])->first();
                if ($dependent) {
                    $dependent->update($payload);
                    $keepIds[] = $dependent->id;
                    continue;
                }
            }
            $created = $user->dependents()->create($payload);
            $keepIds[] = $created->id;
        }

        $user->dependents()->whereNotIn('id', $keepIds ?: [0])->delete();
    }

    private function updateLeaves(Request $request, User $user): void
    {
        $data = $request->validate([
            'leaves' => ['array'],
            'leaves.*.id' => ['nullable', 'integer'],
            'leaves.*.leave_type_id' => ['nullable', 'exists:leave_types,id'],
            'leaves.*.start_date' => ['nullable', 'date'],
            'leaves.*.end_date' => ['nullable', 'date'],
            'leaves.*.note' => ['nullable', 'string', 'max:255'],
        ]);

        $rows = $data['leaves'] ?? [];
        $keepIds = [];

        foreach ($rows as $row) {
            $payload = [
                'leave_type_id' => $row['leave_type_id'] ?? null,
                'start_date' => $row['start_date'] ?? null,
                'end_date' => $row['end_date'] ?? null,
                'note' => $row['note'] ?? null,
            ];
            if (! empty($row['id'])) {
                $leave = $user->leaves()->whereKey($row['id'])->first();
                if ($leave) {
                    $leave->update($payload);
                    $keepIds[] = $leave->id;
                    continue;
                }
            }
            $created = $user->leaves()->create($payload);
            $keepIds[] = $created->id;
        }

        $user->leaves()->whereNotIn('id', $keepIds ?: [0])->delete();
    }

    private function updateNote(Request $request, User $user): void
    {
        $data = $request->validate([
            'employee_note' => ['nullable', 'string', 'max:5000'],
        ]);
        $user->update(['employee_note' => $data['employee_note'] ?? null]);
    }

    private function savePayroll(User $user, array $attributes): void
    {
        $user->employeePayroll()->updateOrCreate(['user_id' => $user->id], $attributes);
    }

    public function destroy(User $user)
    {
        if ($user->resume_path) {
            Storage::disk('local')->delete($user->resume_path);
        }
        if ($user->identification_document_path) {
            Storage::disk('local')->delete($user->identification_document_path);
        }

        $user->delete();

        return redirect()->route('admin.users.index')->with('success', '従業員を削除しました');
    }

    public function toggleActive(User $user)
    {
        $fromStatus = $user->employment_status;
        $user->update(['is_active' => ! $user->is_active]);
        $toStatus = $user->fresh()->employment_status;

        if ($fromStatus !== $toStatus) {
            UserStatusHistory::create([
                'user_id' => $user->id,
                'changed_by' => Auth::guard('admin')->id(),
                'from_status' => $fromStatus,
                'to_status' => $toStatus,
            ]);
        }

        return back()->with('success', 'ステータスを変更しました');
    }

    public function downloadDocument(User $user, string $type)
    {
        $field = $type === 'resume' ? 'resume_path' : 'identification_document_path';
        $path = $user->$field;

        if (! $path || ! Storage::disk('local')->exists($path)) {
            abort(404);
        }

        return response()->file(Storage::disk('local')->path($path));
    }

    /** 一覧の並び順を会社設定に従って適用 */
    private function applySort($query): void
    {
        $key = Setting::getValue('employee_sort_key', 'employee_no_number');
        $dir = Setting::getValue('employee_sort_direction', 'asc') === 'desc' ? 'desc' : 'asc';

        switch ($key) {
            case 'name':
                $query->orderBy('name', $dir);
                break;
            case 'join_date':
                $query->orderBy('joined_at', $dir)->orderBy('name');
                break;
            case 'employee_no_text':
            case 'employee_no_number':
            default:
                // 従業員番号（employee_payrolls.employee_no）の自然順。未設定は末尾。
                $query->orderByEmployeeNo();
                break;
        }
    }

    /** @return array<string, mixed> */
    /**
     * 支給項目（従業員別金額）の保存。calc_method='employee' の項目のみ upsert する。
     */
    private function updateSalaryItems(Request $request, User $user): void
    {
        $data = $request->validate([
            'items' => ['array'],
            'items.*.pay_item_master_id' => ['required', 'integer', 'exists:pay_item_masters,id'],
            'items.*.amount' => ['nullable', 'integer'],
        ]);

        $payType = $user->employeePayroll->pay_type ?? 'monthly';
        // 対象 pay_type かつ employee 計算の項目のみ許可
        $allowed = PayItemMaster::query()
            ->where('pay_type', $payType)
            ->where('calc_method', 'employee')
            ->pluck('id')
            ->all();

        DB::transaction(function () use ($data, $user, $allowed) {
            foreach ($data['items'] ?? [] as $item) {
                $masterId = (int) $item['pay_item_master_id'];
                if (! in_array($masterId, $allowed, true)) {
                    continue;
                }
                EmployeePayItemValue::updateOrCreate(
                    ['user_id' => $user->id, 'pay_item_master_id' => $masterId],
                    ['amount' => (int) ($item['amount'] ?? 0)],
                );
            }
        });

        // base_salary 列との後方互換（旧ロジックのフォールバックと整合させる）
        $baseItem = PayItemMaster::where('pay_type', $payType)->where('code', 'base_salary')->first();
        if ($baseItem && $user->employeePayroll) {
            $baseVal = collect($data['items'] ?? [])->firstWhere('pay_item_master_id', $baseItem->id);
            if ($baseVal !== null) {
                $user->employeePayroll->update(['base_salary' => (int) ($baseVal['amount'] ?? 0)]);
            }
        }
    }

    /**
     * 通勤手当ルートを全置換で保存。
     */
    private function updateCommute(Request $request, User $user): void
    {
        $data = $request->validate([
            'routes' => ['array'],
            'routes.*.transport_type' => ['required', 'string', 'max:20'],
            'routes.*.from_place' => ['nullable', 'string', 'max:255'],
            'routes.*.to_place' => ['nullable', 'string', 'max:255'],
            'routes.*.one_way_distance_km' => ['nullable', 'numeric', 'min:0'],
            'routes.*.condition' => ['required', 'in:fixed,by_workdays'],
            'routes.*.payment_months' => ['nullable', 'array'],
            'routes.*.payment_months.*' => ['integer', 'between:1,12'],
            'routes.*.attendance_item_code' => ['nullable', 'string', 'max:100'],
            'routes.*.amount' => ['nullable', 'integer', 'min:0'],
            'routes.*.payment_method' => ['required', 'in:cash,in_kind'],
            'routes.*.cap_amount' => ['nullable', 'integer', 'min:0'],
            'routes.*.non_taxable_limit' => ['nullable', 'integer', 'min:0'],
            'routes.*.uses_parking' => ['nullable', 'boolean'],
            'routes.*.parking_condition' => ['nullable', 'in:fixed,by_workdays'],
            'routes.*.parking_payment_months' => ['nullable', 'array'],
            'routes.*.parking_payment_months.*' => ['integer', 'between:1,12'],
            'routes.*.parking_attendance_item_code' => ['nullable', 'string', 'max:100'],
            'routes.*.parking_amount' => ['nullable', 'integer', 'min:0'],
            'routes.*.parking_payment_method' => ['nullable', 'in:cash,in_kind'],
            'routes.*.parking_cap_amount' => ['nullable', 'integer', 'min:0'],
        ]);

        // 通勤手段ごとの入力欄（MF準拠）
        $withDistance = ['car', 'motorbike', 'bicycle', 'walk']; // 交通用具＋徒歩は片道距離あり
        $withParking = ['car', 'motorbike', 'bicycle'];          // 交通用具のみ駐車場あり

        DB::transaction(function () use ($data, $user, $withDistance, $withParking) {
            $user->commuteRoutes()->delete();
            foreach (array_values($data['routes'] ?? []) as $i => $r) {
                $type = $r['transport_type'];
                $hasDistance = in_array($type, $withDistance, true);
                $usesParking = in_array($type, $withParking, true) && (bool) ($r['uses_parking'] ?? false);
                $parkingCond = $r['parking_condition'] ?? 'fixed';

                $user->commuteRoutes()->create([
                    'sort_order' => $i,
                    'transport_type' => $type,
                    'from_place' => $r['from_place'] ?? null,
                    'to_place' => $r['to_place'] ?? null,
                    'one_way_distance_km' => $hasDistance ? (float) ($r['one_way_distance_km'] ?? 0) : 0,
                    'condition' => $r['condition'],
                    'payment_months' => $r['condition'] === 'fixed' ? array_values($r['payment_months'] ?? []) : null,
                    'attendance_item_code' => $r['condition'] === 'by_workdays' ? ($r['attendance_item_code'] ?? null) : null,
                    'amount' => (int) ($r['amount'] ?? 0),
                    'payment_method' => $r['payment_method'],
                    'cap_amount' => $r['cap_amount'] ?? null,
                    'non_taxable_limit' => $r['non_taxable_limit'] ?? null,
                    'uses_parking' => $usesParking,
                    'parking_condition' => $usesParking ? $parkingCond : 'fixed',
                    'parking_payment_months' => $usesParking && $parkingCond === 'fixed' ? array_values($r['parking_payment_months'] ?? []) : null,
                    'parking_attendance_item_code' => $usesParking && $parkingCond === 'by_workdays' ? ($r['parking_attendance_item_code'] ?? null) : null,
                    'parking_amount' => $usesParking ? (int) ($r['parking_amount'] ?? 0) : 0,
                    'parking_payment_method' => $usesParking ? ($r['parking_payment_method'] ?? 'cash') : 'cash',
                    'parking_cap_amount' => $usesParking ? ($r['parking_cap_amount'] ?? null) : null,
                ]);
            }
        });

        // 通勤手当の従来列を後方互換で更新（ルート合算＝MFと同じ非課税分割。駐車場代は課税扱い）
        if ($user->employeePayroll) {
            $nonTax = 0;
            $tax = 0;
            foreach ($user->commuteRoutes()->get() as $r) {
                if ($r->condition !== 'fixed') {
                    continue; // 出勤日数連動は月次計算に委ねる
                }
                $amount = (int) $r->amount;
                $limit = $r->non_taxable_limit;
                if ($limit === null) {
                    $nonTax += $amount;
                } else {
                    $n = min($amount, (int) $limit);
                    $nonTax += $n;
                    $tax += max(0, $amount - $n);
                }
                // 駐車場代（定額分）は課税として合算
                if ($r->uses_parking && $r->parking_condition === 'fixed') {
                    $tax += (int) $r->parking_amount;
                }
            }
            $user->employeePayroll->update([
                'commute_allowance_taxable' => $tax,
                'commute_allowance_non_taxable' => $nonTax,
            ]);
        }
    }

    private function payCalcMethodLabel(?string $method): string
    {
        return match ($method) {
            'employee' => '従業員情報で設定',
            'manual' => '毎月手入力',
            'allowance_base', 'deduction_base' => '割増・単価連動',
            'hourly1' => '時給1',
            'hourly2' => '時給2',
            'daily1' => '日給1',
            'daily2' => '日給2',
            'custom' => 'カスタム計算式',
            default => (string) $method,
        };
    }

    private function detailOptions(): array
    {
        return [
            'departments' => Department::orderBy('sort_order')->get(['id', 'name']),
            'businessLocations' => BusinessLocation::orderBy('sort_order')->orderBy('id')->get(['id', 'name']),
            'jobTitles' => JobTitle::orderBy('sort_order')->get(['id', 'name']),
            'closingDateGroups' => ClosingDateGroup::orderBy('sort_order')->get(['id', 'name']),
            'leaveTypes' => LeaveType::where('is_active', true)->orderBy('sort_order')->get(['id', 'name']),
            'employmentTypes' => $this->employmentTypeLabels(),
            'payTypes' => $this->payTypeLabels(),
            'taxTables' => ['kou' => '甲欄', 'otsu' => '乙欄'],
            'accountTypes' => ['ordinary' => '普通', 'checking' => '当座', 'savings' => '貯蓄'],
            'genders' => $this->genderLabels(),
            'disabilityTypes' => ['none' => '対象外', 'general' => '一般障害者', 'special' => '特別障害者'],
            'dependentTypes' => [
                'general' => '一般の扶養親族',
                'specific' => '特定扶養親族',
                'elderly_live_together' => '老人扶養親族(同居)',
                'elderly' => '老人扶養親族',
                'spouse' => '控除対象配偶者',
                'other' => 'その他',
            ],
            'residencyTypes' => ['resident' => '居住者', 'non_resident' => '非居住者'],
            'transportTypes' => [
                'train' => '電車',
                'bus' => 'バス',
                'car' => '自動車',
                'motorbike' => 'バイク',
                'bicycle' => '自転車',
                'walk' => '徒歩',
            ],
            'commuteConditions' => [
                'fixed' => '定額で支給',
                'by_workdays' => '出勤日数に応じて支給',
            ],
            'commutePaymentMethods' => [
                'cash' => '金銭',
                'in_kind' => '現物',
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function defaultPayroll(): array
    {
        return [
            'business_location_id' => null,
            'job_title_id' => null,
            'closing_date_group_id' => null,
            'employee_no' => null,
            'employment_type' => 'full_time',
            'pay_type' => 'monthly',
            'position' => null,
            'work_hours_per_day' => null,
            'work_days_per_month' => null,
            'work_days_monthly_avg' => null,
            'work_hours_per_month' => null,
            'work_hours_monthly_avg' => null,
            'base_salary' => 0,
            'hourly_wage' => 0,
            'hourly_wage2' => 0,
            'daily_wage' => 0,
            'daily_wage2' => 0,
            'tax_table' => 'kou',
            'dependents_count' => 0,
            'is_widow' => false,
            'is_single_parent' => false,
            'disability_type' => 'none',
            'is_working_student' => false,
            'is_minor' => false,
            'is_disaster' => false,
            'is_foreigner' => false,
            'residency_type' => 'resident',
            'is_social_insurance_enrolled' => false,
            'is_employment_insurance_enrolled' => false,
            'is_care_insurance_target' => false,
            'care_insurance_override' => null,
            'standard_reward_health' => null,
            'standard_reward_pension' => null,
            'commute_allowance_taxable' => 0,
            'commute_allowance_non_taxable' => 0,
            'resident_tax_monthly' => 0,
            'resident_tax_june' => 0,
            'bank_name' => null,
            'bank_code' => null,
            'branch_name' => null,
            'branch_code' => null,
            'account_type' => 'ordinary',
            'account_number' => null,
            'account_holder_kana' => null,
            'resident_tax_municipality' => null,
            'resident_tax_recipient_number' => null,
            'report_municipality' => null,
        ];
    }

    /** @return array<string, string> */
    private function employmentTypeLabels(): array
    {
        return [
            'executive' => '役員',
            'employee_executive' => '使用人兼務役員',
            'full_time' => '正社員',
            'contract' => '契約社員',
            'entrusted' => '嘱託社員',
            'part_time' => 'パート',
            'arbeit' => 'アルバイト',
            'dispatch' => '派遣社員',
            'other' => 'その他',
        ];
    }

    /** @return array<string, string> */
    private function payTypeLabels(): array
    {
        return ['monthly' => '月給', 'hourly' => '時給', 'daily' => '日給'];
    }

    /** @return array<string, string> */
    private function genderLabels(): array
    {
        return ['male' => '男性', 'female' => '女性', 'other' => 'その他'];
    }
}
