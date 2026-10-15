<?php

/*
|--------------------------------------------------------------------------
| Permission catalogue and default roles
|--------------------------------------------------------------------------
| Permissions are stored in the database (synced by RolesAndPermissionsSeeder
| / `php artisan salon:sync-permissions`). Only keys listed here are valid.
| The Super Administrator role bypasses checks via Gate::before.
*/

return [
    'catalog' => [
        'Dashboard' => [
            'dashboard.view' => 'View dashboard',
            'dashboard.financials' => 'View financial dashboard metrics',
        ],
        'Customers' => [
            'customers.view' => 'View customers',
            'customers.create' => 'Register customers',
            'customers.update' => 'Edit customers',
            'customers.archive' => 'Archive customers',
            'customers.export' => 'Export customer data',
            'customers.sensitive' => 'View allergies / service history notes',
        ],
        'Services' => [
            'services.view' => 'View service catalogue',
            'services.manage' => 'Manage services',
            'services.price' => 'Change service prices',
        ],
        'Appointments' => [
            'appointments.view' => 'View appointments',
            'appointments.all' => 'View all staff appointments (otherwise only own)',
            'appointments.create' => 'Book appointments',
            'appointments.update' => 'Edit / reschedule appointments',
            'appointments.status' => 'Change appointment status',
            'appointments.cancel' => 'Cancel appointments',
            'appointments.override' => 'Override booking rules / edit completed',
        ],
        'Employees' => [
            'employees.view' => 'View employee directory',
            'employees.manage' => 'Manage employees',
            'employees.sensitive' => 'View payroll / sensitive HR data',
            'employees.schedule' => 'Manage schedules and shifts',
            'employees.attendance' => 'Manage attendance',
            'employees.leave' => 'Approve leave requests',
            'employees.commissions' => 'Manage commissions',
        ],
        'Point of Sale' => [
            'pos.use' => 'Use POS checkout',
            'pos.discount' => 'Apply discounts up to the configured limit',
            'pos.discount_unlimited' => 'Apply discounts above the configured limit',
            'pos.refund' => 'Process refunds',
            'pos.void' => 'Void invoices and delete other users\' drafts',
            'pos.invoices' => 'View invoices and payments',
            'pos.drawer' => 'Open, move cash in/out of and close own cash drawer session',
            'pos.close' => 'Review all drawer sessions, close others\' sessions, daily summary',
        ],
        'Inventory' => [
            'inventory.view' => 'View products and stock levels',
            'inventory.costs' => 'View cost prices and inventory valuation',
            'inventory.manage' => 'Manage products and categories',
            'inventory.suppliers' => 'Manage suppliers',
            'inventory.purchasing' => 'Manage purchase orders / receipts',
            'inventory.adjust' => 'Adjust stock, record damage / expiry',
        ],
        'Finance' => [
            'expenses.view' => 'View expenses',
            'expenses.create' => 'Record expenses',
            'expenses.approve' => 'Approve expenses',
            'finance.view' => 'View financial summaries',
        ],
        'Loyalty' => [
            'loyalty.view' => 'View loyalty and memberships',
            'loyalty.manage' => 'Manage loyalty, plans and promotions',
        ],
        'Reports' => [
            'reports.view' => 'View reports',
            'reports.financial' => 'View financial reports',
            'reports.export' => 'Export reports',
        ],
        'Administration' => [
            'users.view' => 'View users',
            'users.manage' => 'Create / edit / deactivate users',
            'roles.manage' => 'Manage roles and permissions',
            'settings.manage' => 'Manage settings',
            'audit.view' => 'View audit log',
            'notifications.manage' => 'Manage notification settings',
        ],
    ],

    // Super Administrator implicitly has everything (Gate::before); no list needed.
    'roles' => [
        'super-admin' => ['name' => 'Super Administrator', 'permissions' => []],
        'owner' => ['name' => 'Salon Owner', 'permissions' => [
            'dashboard.*', 'customers.*', 'services.*', 'appointments.*', 'employees.*', 'pos.*',
            'inventory.*', 'expenses.*', 'finance.*', 'loyalty.*', 'reports.*',
            'users.view', 'audit.view', 'settings.manage', 'notifications.manage',
        ]],
        'manager' => ['name' => 'Salon Manager', 'permissions' => [
            'dashboard.*', 'customers.*', 'services.*', 'appointments.*', 'employees.view',
            'employees.schedule', 'employees.attendance', 'employees.leave', 'pos.use', 'pos.discount', 'pos.invoices',
            'pos.refund', 'pos.void', 'pos.drawer', 'pos.close', 'inventory.view', 'inventory.costs', 'inventory.adjust', 'expenses.view', 'expenses.create',
            'loyalty.*', 'reports.view', 'reports.export',
        ]],
        'receptionist' => ['name' => 'Receptionist', 'permissions' => [
            'dashboard.view', 'customers.view', 'customers.create', 'customers.update', 'services.view',
            'appointments.view', 'appointments.all', 'appointments.create', 'appointments.update', 'appointments.status',
            'appointments.cancel', 'employees.view', 'loyalty.view',
        ]],
        'stylist' => ['name' => 'Stylist / Beautician', 'permissions' => [
            'dashboard.view', 'customers.view', 'customers.sensitive', 'services.view',
            'appointments.view', 'appointments.status',
        ]],
        'cashier' => ['name' => 'Cashier', 'permissions' => [
            'dashboard.view', 'customers.view', 'services.view', 'appointments.view',
            'pos.use', 'pos.invoices', 'pos.drawer', 'inventory.view', 'loyalty.view', 'appointments.all',
        ]],
        'inventory-manager' => ['name' => 'Inventory Manager', 'permissions' => [
            'dashboard.view', 'inventory.*', 'reports.view',
        ]],
        'accountant' => ['name' => 'Accountant', 'permissions' => [
            'dashboard.view', 'dashboard.financials', 'pos.invoices', 'expenses.*', 'finance.view',
            'reports.view', 'reports.financial', 'reports.export', 'inventory.view', 'inventory.costs',
        ]],
        'hr-officer' => ['name' => 'HR Officer', 'permissions' => [
            'dashboard.view', 'employees.*',
        ]],
    ],
];
