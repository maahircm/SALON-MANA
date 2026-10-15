<?php

namespace App\Http\Controllers;

use App\Enums\AppointmentStatus;
use App\Http\Requests\CustomerRequest;
use App\Models\Customer;
use App\Models\CustomerPreference;
use App\Models\Employee;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CustomerController extends Controller
{
    /** Fields whose values are safe to store in the audit log; PII fields are recorded by name only. */
    private const AUDIT_VALUES = ['status', 'marketing_consent', 'preferred_employee_id'];

    public function __construct(private AuditLogger $audit) {}

    public function index(Request $request)
    {
        $this->authorize('customers.view');
        $customers = $this->filtered($request)->with('preferredEmployee:id,name')->orderBy('name')->paginate(15)->withQueryString();

        return view('customers.index', compact('customers'));
    }

    public function create()
    {
        $this->authorize('customers.create');

        return view('customers.form', ['customer' => new Customer(['status' => 'active']), 'employees' => $this->employees()]);
    }

    public function store(CustomerRequest $request)
    {
        $data = $request->validated();
        if (! $request->boolean('confirm_duplicate') && ($dups = $this->duplicates($data['phone']))->isNotEmpty()) {
            return back()->withInput()->with('duplicates', $dups->map(fn ($c) => ['id' => $c->id, 'name' => $c->name, 'number' => $c->customer_number])->all());
        }

        $customer = DB::transaction(function () use ($request, $data) {
            $customer = new Customer(collect($data)->except(['confirm_duplicate', 'marketing_consent'])->all());
            $consent = $request->boolean('marketing_consent');
            $customer->forceFill([
                'marketing_consent' => $consent,
                'marketing_consent_at' => $consent ? now() : null,
                'registered_on' => now()->toDateString(),
                'created_by' => $request->user()->id,
                'updated_by' => $request->user()->id,
            ])->save();
            $customer->forceFill(['customer_number' => sprintf('CUS-%06d', $customer->id)])->save();
            $this->audit->log('customer.created', $customer, null, ['customer_number' => $customer->customer_number, 'marketing_consent' => $consent]);

            return $customer;
        });

        return redirect()->route('customers.show', $customer)->with('success', "Customer {$customer->customer_number} registered.");
    }

    public function show(Customer $customer, Request $request)
    {
        $this->authorize('view', $customer);
        $canSensitive = $request->user()->can('customers.sensitive');
        $preferences = $customer->preferences()->when(! $canSensitive, fn ($q) => $q->where('is_sensitive', false))->latest()->get();
        $appointments = $customer->appointments()->with('lines.service:id,name')->latest('start_at')->limit(25)->get();
        // Invoices / purchases / loyalty are attached by the POS and loyalty phases.

        return view('customers.show', compact('customer', 'preferences', 'appointments', 'canSensitive'));
    }

    public function edit(Customer $customer)
    {
        $this->authorize('update', $customer);

        return view('customers.form', ['customer' => $customer, 'employees' => $this->employees()]);
    }

    public function update(CustomerRequest $request, Customer $customer)
    {
        $data = $request->validated();
        if ($customer->phone_normalized !== Customer::normalizePhone($data['phone']) && ! $request->boolean('confirm_duplicate')
            && ($dups = $this->duplicates($data['phone'], $customer->id))->isNotEmpty()) {
            return back()->withInput()->with('duplicates', $dups->map(fn ($c) => ['id' => $c->id, 'name' => $c->name, 'number' => $c->customer_number])->all());
        }

        DB::transaction(function () use ($request, $customer, $data) {
            $original = $customer->getOriginal();
            $customer->fill(collect($data)->except(['confirm_duplicate', 'marketing_consent'])->all());
            $consent = $request->boolean('marketing_consent');
            if ($consent !== $customer->marketing_consent) {
                $customer->marketing_consent_at = $consent ? now() : null;
            }
            $customer->marketing_consent = $consent;
            $customer->updated_by = $request->user()->id;
            $customer->save();

            $changed = array_keys(array_diff_key($customer->getChanges(), ['updated_at' => 1, 'updated_by' => 1, 'phone_normalized' => 1]));
            $before = $after = [];
            foreach ($changed as $field) {
                $before[$field] = in_array($field, self::AUDIT_VALUES, true) ? ($original[$field] ?? null) : '[changed]';
                $after[$field] = in_array($field, self::AUDIT_VALUES, true) ? $customer->getAttribute($field) : '[changed]';
            }
            if ($changed) {
                $this->audit->log('customer.updated', $customer, $before, $after);
            }
        });

        return redirect()->route('customers.show', $customer)->with('success', 'Customer updated.');
    }

    /** Archive = soft delete: history is retained, customer disappears from lists and cannot be booked. */
    public function destroy(Customer $customer, Request $request)
    {
        $this->authorize('archive', $customer);
        $customer->delete();
        $this->audit->log('customer.archived', $customer, null, null, $request->input('reason'));

        return redirect()->route('customers.index')->with('success', 'Customer archived.');
    }

    public function restore(int $id, Request $request)
    {
        $customer = Customer::onlyTrashed()->findOrFail($id);
        $this->authorize('archive', $customer);
        abort_if($customer->anonymized_at !== null, 422, 'Anonymized customers cannot be restored.');
        $customer->restore();
        $this->audit->log('customer.restored', $customer);

        return redirect()->route('customers.show', $customer)->with('success', 'Customer restored.');
    }

    /**
     * Privacy erasure: removes personal data but keeps the row (and its appointments / future invoices)
     * so financial history stays intact.
     */
    public function anonymize(int $id, Request $request)
    {
        $customer = Customer::withTrashed()->findOrFail($id);
        $this->authorize('archive', $customer);
        $request->validate(['reason' => ['required', 'string', 'max:500']]);
        if ($customer->anonymized_at) {
            throw ValidationException::withMessages(['customer' => 'Already anonymized.']);
        }
        if ($customer->appointments()->whereIn('status', AppointmentStatus::blockingValues())->exists()) {
            throw ValidationException::withMessages(['customer' => 'Cancel or complete the customer\'s open appointments before anonymizing.']);
        }

        DB::transaction(function () use ($customer, $request) {
            $customer->preferences()->delete();
            $customer->forceFill([
                'name' => 'Anonymized customer', 'phone' => null, 'phone_normalized' => null, 'email' => null, 'date_of_birth' => null,
                'gender' => null, 'address' => null, 'notes' => null, 'preferred_employee_id' => null, 'marketing_consent' => false,
                'marketing_consent_at' => null, 'status' => 'inactive', 'anonymized_at' => now(), 'updated_by' => $request->user()->id,
            ])->save();
            $customer->trashed() || $customer->delete();
            $this->audit->log('customer.anonymized', $customer, null, ['customer_number' => $customer->customer_number], $request->input('reason'));
        });

        return redirect()->route('customers.index')->with('success', 'Customer personal data erased.');
    }

    public function storePreference(Request $request, Customer $customer)
    {
        $this->authorize('update', $customer);
        $data = $request->validate([
            'type' => ['required', 'in:'.implode(',', array_keys(CustomerPreference::TYPES))],
            'value' => ['required', 'string', 'max:1000'],
        ]);
        $sensitive = CustomerPreference::TYPES[$data['type']][1];
        if ($sensitive) {
            $this->authorize('customers.sensitive');
        }
        $pref = new CustomerPreference($data);
        $pref->customer_id = $customer->id;
        $pref->forceFill(['is_sensitive' => $sensitive, 'created_by' => $request->user()->id])->save();
        $this->audit->log('customer.preference_added', $customer, null, ['type' => $data['type']]);

        return back()->with('success', 'Saved.');
    }

    public function destroyPreference(Customer $customer, CustomerPreference $preference, Request $request)
    {
        $this->authorize('update', $customer);
        abort_unless($preference->customer_id === $customer->id, 404);
        if ($preference->is_sensitive) {
            $this->authorize('customers.sensitive');
        }
        $preference->delete();
        $this->audit->log('customer.preference_removed', $customer, ['type' => $preference->type], null);

        return back()->with('success', 'Removed.');
    }

    /** Typeahead for booking / POS. Returns only fields needed to pick a customer. */
    public function search(Request $request)
    {
        abort_unless($request->user()->canAny(['customers.view', 'appointments.create', 'pos.use']), 403);
        $q = trim((string) $request->query('q'));
        if (mb_strlen($q) < 2) {
            return response()->json([]);
        }
        $digits = Customer::normalizePhone($q);

        return response()->json(Customer::query()->where('status', 'active')
            ->where(fn ($w) => $w->where('name', 'like', "%{$q}%")->orWhere('customer_number', 'like', "%{$q}%")
                ->when($digits, fn ($w2) => $w2->orWhere('phone_normalized', 'like', "%{$digits}%")))
            ->orderBy('name')->limit(10)->get(['id', 'customer_number', 'name', 'phone']));
    }

    public function export(Request $request): StreamedResponse
    {
        $this->authorize('customers.export');
        $query = $this->filtered($request)->orderBy('id');
        $count = (clone $query)->count();
        $this->audit->log('customer.exported', 'customer', null, ['rows' => $count, 'filters' => $request->only(['q', 'status', 'consent', 'archived', 'birthday_month'])]);

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Customer number', 'Name', 'Phone', 'Email', 'Date of birth', 'Gender', 'Status', 'Marketing consent', 'Registered on']);
            $query->chunkById(500, function ($rows) use ($out) {
                foreach ($rows as $c) {
                    fputcsv($out, array_map([$this, 'csvSafe'], [
                        $c->customer_number, $c->name, $c->phone, $c->email, $c->date_of_birth?->toDateString(), $c->gender,
                        $c->status, $c->marketing_consent ? 'yes' : 'no', $c->registered_on?->toDateString(),
                    ]));
                }
            });
            fclose($out);
        }, 'customers-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv']);
    }

    /** Neutralise spreadsheet formula injection. */
    private function csvSafe(mixed $v): string
    {
        $v = (string) $v;

        return $v !== '' && str_contains('=+-@', $v[0]) ? "'".$v : $v;
    }

    private function filtered(Request $request)
    {
        return Customer::query()
            ->when($request->query('archived') === '1', fn ($q) => $q->onlyTrashed())
            ->when($request->query('q'), function ($q, $s) {
                $digits = Customer::normalizePhone($s);
                $q->where(fn ($w) => $w->where('name', 'like', "%{$s}%")->orWhere('email', 'like', "%{$s}%")->orWhere('customer_number', 'like', "%{$s}%")
                    ->when($digits, fn ($w2) => $w2->orWhere('phone_normalized', 'like', "%{$digits}%")));
            })
            ->when($request->query('status'), fn ($q, $v) => $q->where('status', $v))
            ->when($request->query('consent') === '1', fn ($q) => $q->where('marketing_consent', true))
            ->when($request->query('birthday_month'), fn ($q, $m) => $q->whereMonth('date_of_birth', (int) $m));
    }

    private function duplicates(string $phone, ?int $ignoreId = null)
    {
        $digits = Customer::normalizePhone($phone);

        return $digits ? Customer::where('phone_normalized', $digits)->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))->get() : collect();
    }

    private function employees()
    {
        return Employee::where('status', 'active')->orderBy('name')->get(['id', 'name']);
    }
}
