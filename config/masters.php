<?php

/*
| Config-driven master data (CRUD). Field spec: [label, type, rules, options]
| type: text|number|select|bool|textarea|date|email. options: array | 'model:Class,label' | 'config:key'
| refs: [table, column] pairs that block deletion while referenced.
*/

use App\Models;

$active = ['Active', 'bool', 'boolean'];

return [
    'floors' => ['title' => 'Floors', 'model' => Models\Floor::class, 'order' => 'sort_order',
        'fields' => ['name' => ['Floor name', 'text', 'required|string|max:60'], 'sort_order' => ['Sort order', 'number', 'nullable|integer|min:0|max:999']],
        'columns' => ['name', 'sort_order'], 'unique' => ['name'], 'refs' => [['rooms', 'floor_id']]],

    'room-types' => ['title' => 'Room Types', 'model' => Models\RoomType::class, 'order' => 'name',
        'fields' => [
            'code' => ['Code', 'text', 'required|string|max:20'], 'name' => ['Name', 'text', 'required|string|max:80'],
            'base_rate' => ['Base rate', 'number', 'required|numeric|min:0'], 'extra_adult_rate' => ['Extra adult rate', 'number', 'nullable|numeric|min:0'],
            'extra_child_rate' => ['Extra child rate', 'number', 'nullable|numeric|min:0'], 'base_occupancy' => ['Base occupancy', 'number', 'required|integer|min:1|max:10'],
            'max_adults' => ['Max adults', 'number', 'required|integer|min:1|max:10'], 'max_children' => ['Max children', 'number', 'required|integer|min:0|max:10'],
            'amenities' => ['Amenities (comma separated)', 'text', 'nullable|string|max:500'], 'description' => ['Description', 'textarea', 'nullable|string|max:2000'],
            'show_online' => ['Show on booking engine', 'bool', 'boolean'], 'is_active' => $active,
        ],
        'columns' => ['code', 'name', 'base_rate', 'base_occupancy', 'max_adults', 'show_online', 'is_active'], 'unique' => ['code'],
        'refs' => [['rooms', 'room_type_id'], ['reservation_rooms', 'room_type_id']]],

    'rooms' => ['title' => 'Rooms', 'model' => Models\Room::class, 'order' => 'room_no', 'with' => ['roomType', 'floor'],
        'fields' => [
            'room_no' => ['Room no.', 'text', 'required|string|max:20'], 'room_type_id' => ['Room type', 'select', 'required|integer|owned:room_types', 'model:RoomType,name'],
            'floor_id' => ['Floor', 'select', 'nullable|integer|owned:floors', 'model:Floor,name'], 'extension' => ['Phone extension', 'text', 'nullable|string|max:10'],
            'is_smoking' => ['Smoking room', 'bool', 'boolean'], 'notes' => ['Notes', 'text', 'nullable|string|max:255'], 'is_active' => $active,
        ],
        'columns' => ['room_no', 'roomType.name' => 'Type', 'floor.name' => 'Floor', 'status', 'hk_status', 'is_smoking', 'is_active'], 'unique' => ['room_no'],
        'refs' => [['reservation_rooms', 'room_id'], ['maintenance_requests', 'room_id']]],

    'rate-plans' => ['title' => 'Rate Plans', 'model' => Models\RatePlan::class, 'order' => 'room_type_id', 'with' => ['roomType'],
        'fields' => [
            'room_type_id' => ['Room type', 'select', 'required|integer|owned:room_types', 'model:RoomType,name'], 'code' => ['Code', 'text', 'required|string|max:20'],
            'name' => ['Name', 'text', 'required|string|max:80'], 'meal_plan' => ['Meal plan', 'select', 'required|in:EP,CP,MAP,AP', 'config:pms.meal_plans'],
            'rate' => ['Rate / night', 'number', 'required|numeric|min:0'], 'extra_adult_rate' => ['Extra adult', 'number', 'nullable|numeric|min:0'],
            'extra_child_rate' => ['Extra child', 'number', 'nullable|numeric|min:0'], 'tax_inclusive' => ['Rate includes tax', 'bool', 'boolean'], 'is_active' => $active,
        ],
        'columns' => ['roomType.name' => 'Room type', 'code', 'name', 'meal_plan', 'rate', 'tax_inclusive', 'is_active'], 'refs' => [['reservation_rooms', 'rate_plan_id']]],

    'taxes' => ['title' => 'Taxes', 'model' => Models\Tax::class, 'order' => 'applies_to',
        'fields' => [
            'name' => ['Name', 'text', 'required|string|max:60'], 'rate' => ['Rate %', 'number', 'required|numeric|min:0|max:100'],
            'code' => ['Statutory component', 'select', 'required|in:VAT,NHIL,GETFUND,TL', ['VAT' => 'VAT (GRA)', 'NHIL' => 'NHIL (GRA)', 'GETFUND' => 'GETFund levy (GRA)', 'TL' => 'Tourism levy (GTA)']],
            'authority' => ['Collected for', 'select', 'required|in:GRA,GTA', ['GRA' => 'Ghana Revenue Authority', 'GTA' => 'Ghana Tourism Authority']],
            'applies_to' => ['Applies to', 'select', 'required|in:room,fnb,service,all', ['room' => 'Room tariff', 'fnb' => 'Food & beverage', 'service' => 'Other services', 'all' => 'All charges']],
            'slab_from' => ['Tariff slab from', 'number', 'nullable|numeric|min:0'], 'slab_to' => ['Tariff slab to (blank = no limit)', 'number', 'nullable|numeric|gte:slab_from'], 'is_active' => $active,
        ],
        'columns' => ['name', 'code', 'authority', 'rate', 'applies_to', 'is_active'],
        'hint' => 'Ghana (VAT Act 2025): VAT 15%, NHIL 2.5% and GETFund 2.5% on the same tax-exclusive value, plus the 1% Tourism Levy (GTA) at hotels, restaurants and event venues. Each component is shown separately on invoices; slabs are optional (e.g. a rate that only applies above a tariff).'],

    'business-sources' => ['title' => 'Business Sources', 'model' => Models\BusinessSource::class, 'order' => 'name',
        'fields' => ['name' => ['Name', 'text', 'required|string|max:80'], 'type' => ['Type', 'select', 'required|in:direct,walk_in,ota,corporate,travel_agent,booking_engine', ['direct' => 'Direct', 'walk_in' => 'Walk-in', 'ota' => 'OTA', 'corporate' => 'Corporate', 'travel_agent' => 'Travel agent', 'booking_engine' => 'Booking engine']],
            'commission_pct' => ['Commission %', 'number', 'nullable|numeric|min:0|max:100'], 'is_active' => $active],
        'columns' => ['name', 'type', 'commission_pct', 'is_active'], 'unique' => ['name'], 'refs' => [['reservations', 'business_source_id']]],

    'payment-modes' => ['title' => 'Payment Modes', 'model' => Models\PaymentMode::class, 'order' => 'name',
        'fields' => ['name' => ['Name', 'text', 'required|string|max:60'], 'type' => ['Type', 'select', 'required|in:cash,card,upi,bank,wallet,city_ledger,online,forex,cheque', ['cash' => 'Cash', 'card' => 'Card', 'upi' => 'UPI', 'bank' => 'Bank transfer', 'wallet' => 'Wallet', 'city_ledger' => 'City ledger', 'online' => 'Online (payment gateway)', 'forex' => 'Foreign currency (forex desk only)', 'cheque' => 'Cheque (to Cheques in Hand – see Treasury)']], 'account_head_id' => ['GL account (debited on receipt)', 'select', 'nullable|integer|owned:account_heads', 'model:AccountHead,name'], 'is_active' => $active],
        'columns' => ['name', 'type', 'is_active'], 'unique' => ['name'], 'refs' => [['folio_transactions', 'payment_mode_id'], ['pos_orders', 'payment_mode_id'], ['vouchers', 'payment_mode_id']]],

    'companies' => ['title' => 'Companies / Travel Agents', 'model' => Models\Company::class, 'order' => 'name', 'perm' => 'guests.manage',
        'fields' => [
            'name' => ['Name', 'text', 'required|string|max:255'], 'type' => ['Type', 'select', 'required|in:corporate,travel_agent,ota', ['corporate' => 'Corporate', 'travel_agent' => 'Travel agent', 'ota' => 'OTA']],
            'contact_person' => ['Contact person', 'text', 'nullable|string|max:255'], 'phone' => ['Phone', 'text', 'nullable|string|max:30'], 'email' => ['Email', 'email', 'nullable|email|max:255'],
            'tax_number' => ['TIN (GRA / Ghana Card)', 'text', 'nullable|string|max:40|tin'], 'credit_limit' => ['Credit limit', 'number', 'nullable|numeric|min:0'],
            'credit_days' => ['Credit terms (days)', 'number', 'nullable|integer|min:0|max:365'], 'direct_billing' => ['Approved for direct billing (bill to company)', 'bool', 'boolean'],
            'account_manager_id' => ['Account manager', 'select', 'nullable|integer|owned:users', 'model:User,name'],
            'discount_pct' => ['Reference discount % (rates come from Rate Contracts)', 'number', 'nullable|numeric|min:0|max:100'],
            'commission_pct' => ['Agent commission % (on room revenue)', 'number', 'nullable|numeric|min:0|max:50'], 'address' => ['Address', 'textarea', 'nullable|string|max:255'], 'is_active' => $active,
        ],
        'columns' => ['name', 'type', 'contact_person', 'phone', 'tax_number', 'credit_limit', 'discount_pct', 'commission_pct', 'is_active'], 'unique' => ['name'],
        'refs' => [['reservations', 'company_id'], ['folio_transactions', 'company_id'], ['folios', 'company_id'], ['rate_contracts', 'company_id']]],

    'departments' => ['title' => 'Departments', 'model' => Models\Department::class, 'order' => 'name', 'perm' => 'hr.manage',
        'fields' => ['name' => ['Name', 'text', 'required|string|max:80']], 'columns' => ['name'], 'unique' => ['name'], 'refs' => [['employees', 'department_id']]],

    'employees' => ['title' => 'Employees', 'model' => Models\Employee::class, 'order' => 'name', 'perm' => 'hr.manage', 'with' => ['department', 'user'], 'autonumber' => ['emp_code' => 'employee'],
        'fields' => [
            'emp_code' => ['Employee code (blank = auto)', 'text', 'nullable|string|max:30'], 'name' => ['Name', 'text', 'required|string|max:255'],
            'gender' => ['Gender (ESG diversity reporting)', 'select', 'nullable|in:F,M,X', ['F' => 'Female', 'M' => 'Male', 'X' => 'Prefer not to say']], // v7.0
            'nationality' => ['Nationality', 'text', 'nullable|string|max:40'],
            'department_id' => ['Department', 'select', 'nullable|integer|owned:departments', 'model:Department,name'], 'designation' => ['Designation', 'text', 'nullable|string|max:80'],
            'phone' => ['Phone', 'text', 'nullable|string|max:30'], 'email' => ['Email', 'email', 'nullable|email|max:255'], 'joining_date' => ['Joining date', 'date', 'nullable|date'],
            'leaving_date' => ['Leaving date (last working day)', 'date', 'nullable|date'],
            'salary' => ['Monthly basic salary', 'number', 'nullable|numeric|min:0|max:99999999'], 'id_proof' => ['ID proof no.', 'text', 'nullable|string|max:60'], 'address' => ['Address', 'text', 'nullable|string|max:255'],
            'user_id' => ['Login user (HK "My rooms", spa therapist)', 'select', 'nullable|integer|owned:users', 'model:User,name'],
            'payment_mode_id' => ['Salary paid by', 'select', 'nullable|integer|owned:payment_modes', 'model:PaymentMode,name'],
            'bank_name' => ['Bank name', 'text', 'nullable|string|max:80'], 'bank_account' => ['Bank account no.', 'text', 'nullable|string|max:40'],
            'bank_ifsc' => ['Bank sort code / branch', 'text', 'nullable|string|max:20'], 'tax_id' => ['TIN / Ghana Card PIN', 'text', 'nullable|string|max:30'],
            'social_security_no' => ['SSNIT number', 'text', 'nullable|string|max:30'],
            'employment_type' => ['Employment type (Act 651 ss. 73–78)', 'select', 'nullable|in:permanent,fixed_term,weekly,casual',
                ['permanent' => 'Permanent', 'fixed_term' => 'Fixed-term contract', 'weekly' => 'Weekly-paid', 'casual' => 'Casual / daily-rated (5% final tax)']], // v7.43
            'tax_resident' => ['Tax resident (a non-resident is taxed at a flat 25%)', 'bool', 'boolean'], // v7.43
            'tax_relief' => ['Personal reliefs per month, GH¢ (GRA: marriage 100, child education 50 a child, old age 125)', 'number', 'nullable|numeric|min:0|max:99999'], // v7.43
            'ot_eligible' => ['Eligible for overtime', 'bool', 'boolean'], 'is_active' => $active,
        ],
        'columns' => ['emp_code', 'name', 'department.name' => 'Department', 'designation', 'salary', 'user.username' => 'Login', 'is_active'], 'unique' => ['emp_code', 'user_id'],
        'refs' => [['attendances', 'employee_id'], ['maintenance_requests', 'employee_id'], ['housekeeping_tasks', 'employee_id'], ['payslips', 'employee_id'], ['staff_advances', 'employee_id'], ['leave_requests', 'employee_id']]],

    'leave-types' => ['title' => 'Leave Types', 'model' => Models\LeaveType::class, 'order' => 'code', 'perm' => 'hr.manage',
        'fields' => ['code' => ['Code', 'text', 'required|string|max:10'], 'name' => ['Name', 'text', 'required|string|max:60'],
            'days_per_year' => ['Days per year (0 for unpaid)', 'number', 'required|numeric|min:0|max:366'],
            'is_paid' => ['Paid leave (unpaid = loss of pay)', 'bool', 'boolean'],
            'carry_forward_max' => ['Max days carried to next year', 'number', 'nullable|numeric|min:0|max:366'], 'is_active' => $active],
        'columns' => ['code', 'name', 'days_per_year', 'is_paid', 'carry_forward_max', 'is_active'], 'unique' => ['code'], 'refs' => [['leave_requests', 'leave_type_id']]],

    'outlets' => ['title' => 'POS Outlets', 'model' => Models\Outlet::class, 'order' => 'name',
        'fields' => ['name' => ['Name', 'text', 'required|string|max:80'], 'type' => ['Type', 'select', 'required|in:restaurant,bar,room_service,cafe,spa,laundry', ['restaurant' => 'Restaurant', 'bar' => 'Bar', 'room_service' => 'Room service', 'cafe' => 'Café', 'spa' => 'Spa', 'laundry' => 'Laundry']],
            'kot_prefix' => ['KOT prefix', 'text', 'required|string|max:10'], 'service_charge_pct' => ['Service charge %', 'number', 'nullable|numeric|min:0|max:30'],
            'store_id' => ['Consumes stock from (recipes)', 'select', 'nullable|integer|owned:stores', 'model:Store,name'], 'is_active' => $active],
        'columns' => ['name', 'type', 'kot_prefix', 'service_charge_pct', 'store.name' => 'Stock store', 'is_active'], 'with' => ['store'], 'unique' => ['name'], 'refs' => [['pos_orders', 'outlet_id']]],

    'minibar-items' => ['title' => 'Minibar Items', 'model' => Models\MinibarItem::class, 'order' => 'sort_order', 'with' => ['inventoryItem'],
        'fields' => ['name' => ['Item', 'text', 'required|string|max:80'], 'price' => ['Price', 'number', 'required|numeric|min:0|max:999999'],
            'inventory_item_id' => ['Deduct stock item (optional)', 'select', 'nullable|integer|owned:inventory_items', 'model:InventoryItem,name'],
            'sort_order' => ['Sort order', 'number', 'nullable|integer|min:0|max:999'], 'is_active' => $active],
        'columns' => ['name', 'price', 'inventoryItem.name' => 'Stock item', 'sort_order', 'is_active'], 'unique' => ['name']],

    'spa-services' => ['title' => 'Spa Services & Activities', 'model' => Models\SpaService::class, 'order' => 'name',
        'fields' => ['name' => ['Name', 'text', 'required|string|max:100'], 'category' => ['Category', 'select', 'required|in:spa,salon,fitness,activity,tour', Models\SpaService::CATEGORIES],
            'duration_min' => ['Duration (minutes)', 'number', 'required|integer|min:5|max:720'], 'price' => ['Price per person', 'number', 'required|numeric|min:0|max:9999999'],
            'description' => ['Description', 'textarea', 'nullable|string|max:500'], 'is_active' => $active],
        'columns' => ['name', 'category', 'duration_min', 'price', 'is_active'], 'unique' => ['name'], 'refs' => [['spa_appointments', 'spa_service_id']]],

    // v3.3 transport desk
    'currencies' => ['title' => 'Currencies (forex desk)', 'model' => Models\Currency::class, 'order' => 'sort_order', 'perm' => 'forex.manage',
        'fields' => [
            'code' => ['ISO code', 'text', 'required|string|size:3|alpha'], 'name' => ['Name', 'text', 'required|string|max:60'], 'symbol' => ['Symbol', 'text', 'nullable|string|max:8'],
            'unit' => ['Rate quoted per (units)', 'select', 'required|in:1,100', ['1' => '1 unit', '100' => '100 units (e.g. JPY)']],
            'sort_order' => ['Sort order', 'number', 'nullable|integer|min:0|max:999'], 'is_active' => $active,
        ],
        'columns' => ['code', 'name', 'symbol', 'unit', 'sort_order', 'is_active'], 'unique' => ['code'],
        'refs' => [['forex_exchanges', 'currency_id'], ['forex_surrender_lines', 'currency_id']]],

    'vehicles' => ['title' => 'Vehicles', 'model' => Models\Vehicle::class, 'order' => 'code', 'perm' => 'transport.manage',
        'fields' => [
            'code' => ['Call-sign (short code)', 'text', 'required|string|max:20'], 'name' => ['Make & model', 'text', 'required|string|max:80'],
            'reg_no' => ['Registration no.', 'text', 'required|string|max:20'], 'type' => ['Type', 'select', 'required|in:'.implode(',', array_keys(Models\Vehicle::TYPES)), Models\Vehicle::TYPES],
            'seats' => ['Passenger seats', 'number', 'required|integer|min:1|max:60'], 'luggage' => ['Large bags', 'number', 'required|integer|min:0|max:60'],
            'fuel' => ['Fuel', 'select', 'required|in:'.implode(',', array_keys(Models\Vehicle::FUELS)), Models\Vehicle::FUELS], 'color' => ['Colour', 'text', 'nullable|string|max:30'],
            'odometer' => ['Odometer (km)', 'number', 'nullable|integer|min:0|max:9999999'],
            'status' => ['Status', 'select', 'required|in:available,maintenance', ['available' => 'Available', 'maintenance' => 'In maintenance / workshop']],
            'insurance_until' => ['Insurance valid until', 'date', 'nullable|date'], 'permit_until' => ['Permit valid until', 'date', 'nullable|date'],
            'fitness_until' => ['Fitness certificate until', 'date', 'nullable|date'], 'puc_until' => ['PUC valid until', 'date', 'nullable|date'],
            'asset_id' => ['Fixed asset (register)', 'select', 'nullable|integer|owned:assets', 'model:Asset,name'],
            'notes' => ['Notes', 'textarea', 'nullable|string|max:500'], 'is_active' => $active,
        ],
        'columns' => ['code', 'name', 'reg_no', 'type', 'seats', 'status', 'insurance_until', 'is_active'], 'unique' => ['code', 'reg_no'],
        'refs' => [['transport_trips', 'vehicle_id'], ['vehicle_expenses', 'vehicle_id']]],

    'drivers' => ['title' => 'Drivers', 'model' => Models\Driver::class, 'order' => 'name', 'perm' => 'transport.manage', 'with' => ['user'],
        'fields' => [
            'name' => ['Name', 'text', 'required|string|max:100'], 'phone' => ['Mobile', 'text', 'required|string|max:30'],
            'employee_id' => ['Employee record', 'select', 'nullable|integer|owned:employees', 'model:Employee,name'],
            'user_id' => ['Login (for the My Trips phone screen)', 'select', 'nullable|integer|owned:users', 'model:User,name'],
            'licence_no' => ['Driving licence no.', 'text', 'nullable|string|max:40'], 'licence_until' => ['Licence valid until', 'date', 'nullable|date'],
            'languages' => ['Languages', 'text', 'nullable|string|max:100'], 'notes' => ['Notes', 'textarea', 'nullable|string|max:500'], 'is_active' => $active,
        ],
        'columns' => ['name', 'phone', 'licence_no', 'licence_until', 'user.name' => 'Login', 'is_active'], 'unique' => ['phone'],
        'refs' => [['transport_trips', 'driver_id']]],

    'transport-tariffs' => ['title' => 'Transport Tariff Card', 'model' => Models\TransportTariff::class, 'order' => 'sort_order', 'perm' => 'transport.manage',
        'fields' => [
            'name' => ['Name', 'text', 'required|string|max:80'], 'kind' => ['Route', 'select', 'required|in:'.implode(',', array_keys(Models\TransportTariff::KINDS)), Models\TransportTariff::KINDS],
            'vehicle_type' => ['Vehicle type (blank = any)', 'select', 'nullable|in:'.implode(',', array_keys(Models\Vehicle::TYPES)), Models\Vehicle::TYPES],
            'flat_rate' => ['Fare (one way, before tax)', 'number', 'required|numeric|min:0|max:9999999'], 'included_km' => ['Included km', 'number', 'nullable|integer|min:0|max:5000'],
            'per_km' => ['Per extra km', 'number', 'nullable|numeric|min:0|max:99999'], 'wait_per_hour' => ['Waiting per hour', 'number', 'nullable|numeric|min:0|max:99999'],
            'duration_min' => ['Vehicle time, there & back (min)', 'number', 'required|integer|min:15|max:1440'], 'sort_order' => ['Sort order', 'number', 'nullable|integer|min:0|max:999'],
            'is_active' => $active,
        ],
        'columns' => ['name', 'kind', 'vehicle_type', 'flat_rate', 'included_km', 'per_km', 'duration_min', 'is_active'], 'unique' => ['name'],
        'refs' => [['transport_trips', 'transport_tariff_id']]],

    'spa-rooms' => ['title' => 'Treatment Rooms', 'model' => Models\SpaRoom::class, 'order' => 'name',
        'fields' => ['name' => ['Name', 'text', 'required|string|max:60'], 'is_active' => $active],
        'columns' => ['name', 'is_active'], 'unique' => ['name'], 'refs' => [['spa_appointments', 'spa_room_id']]],

    'pos-tables' => ['title' => 'Restaurant Tables', 'model' => Models\PosTable::class, 'order' => 'outlet_id', 'with' => ['outlet'],
        'fields' => ['outlet_id' => ['Outlet', 'select', 'required|integer|owned:outlets', 'model:Outlet,name'], 'table_no' => ['Table no.', 'text', 'required|string|max:20'],
            'capacity' => ['Seats', 'number', 'required|integer|min:1|max:50'], 'section' => ['Section', 'text', 'nullable|string|max:40'], 'is_active' => $active],
        'columns' => ['outlet.name' => 'Outlet', 'table_no', 'capacity', 'section', 'is_active'], 'refs' => [['pos_orders', 'pos_table_id']]],

    'menu-categories' => ['title' => 'Menu Categories', 'model' => Models\MenuCategory::class, 'order' => 'sort_order', 'with' => ['outlet', 'printer'],
        'fields' => ['name' => ['Name', 'text', 'required|string|max:80'], 'outlet_id' => ['Outlet (blank = all)', 'select', 'nullable|integer|owned:outlets', 'model:Outlet,name'],
            'kitchen_station' => ['Kitchen station (KDS)', 'text', 'nullable|string|max:40'],
            'kot_printer_id' => ['KOT printer (blank = match the station)', 'select', 'nullable|integer|owned:kot_printers', 'model:KotPrinter,name'],
            'sort_order' => ['Sort order', 'number', 'nullable|integer|min:0|max:999'],
            'fb_class' => ['Revenue class (F&B cost)', 'select', 'required|in:food,beverage', ['food' => 'Food', 'beverage' => 'Beverage']]], // v6.8
        'columns' => ['name', 'outlet.name' => 'Outlet', 'kitchen_station' => 'Station', 'printer.name' => 'KOT printer', 'fb_class' => 'Class', 'sort_order'], 'refs' => [['menu_items', 'menu_category_id']]],

    'menu-items' => ['title' => 'Menu Items', 'model' => Models\MenuItem::class, 'order' => 'name', 'with' => ['category', 'outlet', 'printer'],
        'hint' => 'A photo is shown on the POS till, the table QR menu, in-room dining and the guest TV. JPG, PNG or WebP, up to 2 MB.',
        'fields' => [
            'code' => ['Code', 'text', 'nullable|string|max:20'], 'name' => ['Name', 'text', 'required|string|max:120'],
            'menu_category_id' => ['Category', 'select', 'required|integer|owned:menu_categories', 'model:MenuCategory,name'], 'outlet_id' => ['Outlet (blank = all)', 'select', 'nullable|integer|owned:outlets', 'model:Outlet,name'],
            'kot_printer_id' => ['KOT printer (blank = category / station)', 'select', 'nullable|integer|owned:kot_printers', 'model:KotPrinter,name'],
            'price' => ['Price', 'number', 'required|numeric|min:0'], 'tax_rate' => ['Tax %', 'number', 'required|numeric|min:0|max:50'],
            'food_type' => ['Type', 'select', 'required|in:veg,non_veg,egg,beverage', ['veg' => 'Veg', 'non_veg' => 'Non-veg', 'egg' => 'Egg', 'beverage' => 'Beverage']],
            'description' => ['Description', 'text', 'nullable|string|max:255'],
            'image' => ['Photo', 'image', 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048', 'image_path'],
            'is_available' => ['Available', 'bool', 'boolean'],
        ],
        'columns' => ['image_path' => 'Photo', 'code', 'name', 'category.name' => 'Category', 'outlet.name' => 'Outlet', 'printer.name' => 'KOT printer', 'food_type', 'price', 'tax_rate', 'is_available'], 'refs' => [['pos_order_items', 'menu_item_id']]],

    'banquet-halls' => ['title' => 'Banquet Halls & Venues', 'model' => Models\BanquetHall::class, 'order' => 'sort', 'perm' => 'banquet.setup',
        'fields' => ['name' => ['Name', 'text', 'required|string|max:80'], 'code' => ['Short code', 'text', 'nullable|string|max:12'],
            'capacity' => ['Max capacity (pax)', 'number', 'required|integer|min:1'], 'area_sqft' => ['Area (sq ft)', 'number', 'nullable|integer|min:0'],
            'cap_theatre' => ['Theatre', 'number', 'nullable|integer|min:0'], 'cap_classroom' => ['Classroom', 'number', 'nullable|integer|min:0'],
            'cap_banquet' => ['Banquet (rounds)', 'number', 'nullable|integer|min:0'], 'cap_ushape' => ['U-shape', 'number', 'nullable|integer|min:0'],
            'cap_cocktail' => ['Cocktail / standing', 'number', 'nullable|integer|min:0'], 'cap_boardroom' => ['Boardroom', 'number', 'nullable|integer|min:0'],
            'rate_per_session' => ['Rent – half day / session', 'number', 'nullable|numeric|min:0'], 'rate_full_day' => ['Rent – full day', 'number', 'nullable|numeric|min:0'],
            'facilities' => ['Facilities', 'textarea', 'nullable|string|max:500'], 'is_outdoor' => ['Outdoor venue', 'bool', 'boolean'],
            'sort' => ['Display order', 'number', 'nullable|integer|min:0|max:999'], 'is_active' => $active],
        'columns' => ['name', 'capacity', 'cap_theatre' => 'Theatre', 'cap_classroom' => 'Class', 'cap_banquet' => 'Banquet', 'cap_cocktail' => 'Cocktail', 'rate_per_session' => 'Half day', 'rate_full_day' => 'Full day', 'is_active'],
        'unique' => ['name'], 'refs' => [['banquet_events', 'banquet_hall_id'], ['banquet_functions', 'banquet_hall_id']]],

    'banquet-packages' => ['title' => 'Banquet Menu Packages', 'model' => Models\BanquetPackage::class, 'order' => 'sort', 'perm' => 'banquet.setup',
        'fields' => ['name' => ['Name', 'text', 'required|string|max:120'],
            'type' => ['Type', 'select', 'required|in:food,beverage,break', ['food' => 'Food (meal)', 'beverage' => 'Beverage', 'break' => 'Tea / coffee break']],
            'style' => ['Service style', 'select', 'required|in:buffet,plated,cocktail,break,bar', ['buffet' => 'Buffet', 'plated' => 'Plated / served', 'cocktail' => 'Cocktail / passed', 'break' => 'Break station', 'bar' => 'Bar']],
            'price_per_pax' => ['Price per person', 'number', 'required|numeric|min:0'], 'min_pax' => ['Minimum pax', 'number', 'nullable|integer|min:0'],
            'tax_rate' => ['Tax %', 'number', 'required|numeric|min:0|max:50'], 'items' => ['Menu (one item per line)', 'textarea', 'nullable|string|max:3000'],
            'sort' => ['Display order', 'number', 'nullable|integer|min:0|max:999'], 'is_active' => $active],
        'columns' => ['name', 'type', 'style', 'price_per_pax' => 'Per pax', 'min_pax' => 'Min pax', 'tax_rate', 'is_active'], 'unique' => ['name'], 'refs' => [['banquet_functions', 'banquet_package_id']]],

    'banquet-services' => ['title' => 'Banquet Services & Equipment', 'model' => Models\BanquetService::class, 'order' => 'name', 'with' => ['department'], 'perm' => 'banquet.setup',
        'fields' => ['name' => ['Name', 'text', 'required|string|max:120'],
            'category' => ['Category', 'select', 'required|in:av,decor,entertainment,equipment,staffing,other', 'config:pms.banquet_service_categories'],
            'unit' => ['Charged per', 'select', 'required|in:event,day,piece,pax,hour', ['event' => 'Event / function', 'day' => 'Day', 'piece' => 'Piece', 'pax' => 'Person', 'hour' => 'Hour']],
            'price' => ['Price', 'number', 'required|numeric|min:0'], 'tax_rate' => ['Tax %', 'number', 'required|numeric|min:0|max:50'],
            'department_id' => ['Delivered by (department)', 'select', 'nullable|integer|owned:departments', 'model:Department,name'], 'is_active' => $active],
        'columns' => ['name', 'category', 'unit', 'price', 'tax_rate', 'department.name' => 'Department', 'is_active'], 'unique' => ['name'], 'refs' => [['banquet_event_items', 'banquet_service_id']]],

    'stores' => ['title' => 'Stores', 'model' => Models\Store::class, 'order' => 'name',
        'fields' => ['name' => ['Name', 'text', 'required|string|max:80'], 'type' => ['Type', 'select', 'required|in:main,kitchen,bar,housekeeping', ['main' => 'Main store', 'kitchen' => 'Kitchen', 'bar' => 'Bar', 'housekeeping' => 'Housekeeping']]],
        'columns' => ['name', 'type'], 'unique' => ['name'], 'refs' => [['stock_movements', 'store_id'], ['purchase_orders', 'store_id']]],

    'item-categories' => ['title' => 'Item Categories', 'model' => Models\ItemCategory::class, 'order' => 'name',
        'fields' => ['name' => ['Name', 'text', 'required|string|max:80'],
            'fb_class' => ['F&B cost class', 'select', 'nullable|in:food,beverage', ['food' => 'Food cost', 'beverage' => 'Beverage cost']]], // v6.8 blank = not F&B
        'columns' => ['name', 'fb_class' => 'F&B class'], 'unique' => ['name'], 'refs' => [['inventory_items', 'item_category_id']]],

    'inventory-items' => ['title' => 'Inventory Items', 'model' => Models\InventoryItem::class, 'order' => 'name', 'with' => ['category'],
        'fields' => [
            'code' => ['Code', 'text', 'nullable|string|max:30'], 'name' => ['Name', 'text', 'required|string|max:120'],
            'item_category_id' => ['Category', 'select', 'nullable|integer|owned:item_categories', 'model:ItemCategory,name'],
            'unit' => ['Unit', 'select', 'required|string|max:20', ['pcs' => 'pcs', 'kg' => 'kg', 'g' => 'g', 'ltr' => 'ltr', 'ml' => 'ml', 'btl' => 'bottle', 'box' => 'box', 'pkt' => 'packet', 'dozen' => 'dozen', 'roll' => 'roll', 'set' => 'set']],
            'reorder_level' => ['Reorder level', 'number', 'nullable|numeric|min:0'], 'last_cost' => ['Cost / unit', 'number', 'nullable|numeric|min:0'], 'yield_pct' => ['Trim yield %', 'number', 'nullable|numeric|min:1|max:100'],
            'tax_rate' => ['Purchase tax %', 'number', 'nullable|numeric|min:0|max:50'], 'is_active' => $active,
        ],
        'columns' => ['code', 'name', 'category.name' => 'Category', 'unit', 'reorder_level', 'last_cost', 'is_active'], 'refs' => [['stock_movements', 'inventory_item_id'], ['purchase_order_items', 'inventory_item_id']]],

    'suppliers' => ['title' => 'Suppliers', 'model' => Models\Supplier::class, 'order' => 'name',
        'fields' => ['name' => ['Name', 'text', 'required|string|max:255'], 'contact_person' => ['Contact person', 'text', 'nullable|string|max:255'], 'phone' => ['Phone', 'text', 'nullable|string|max:30'],
            'email' => ['Email', 'email', 'nullable|email|max:255'], 'tax_number' => ['TIN (GRA / Ghana Card)', 'text', 'nullable|string|max:40'], 'address' => ['Address', 'text', 'nullable|string|max:255'],
            'payment_terms_days' => ['Payment terms (days)', 'number', 'required|integer|min:0|max:365'],
            // v4.4 Ghana withholding tax on payments (Act 896)
            'wht_category' => ['Withholding tax (GRA)', 'select', 'required|in:'.implode(',', array_keys(\App\Services\WhtService::CATEGORIES)), \App\Services\WhtService::options()],
            'wht_exempt_ref' => ['WHT exemption certificate no.', 'text', 'nullable|string|max:60'],
            'wht_exempt_until' => ['Exemption valid until', 'date', 'nullable|date|required_with:wht_exempt_ref'],
            'expense_head_id' => ['Default expense account (direct bills)', 'select', 'nullable|owned:account_heads', 'model:AccountHead,name'],
            'bank_details' => ['Bank / mobile-money details for payments', 'textarea', 'nullable|string|max:500'],
            'is_local' => ['Local supplier (Ghana-based, for ESG local-sourcing spend)', 'bool', 'boolean'], 'is_active' => $active], // v7.0
        'columns' => ['name', 'contact_person', 'phone', 'tax_number', 'wht_category' => 'WHT', 'payment_terms_days' => 'Terms (days)', 'is_local' => 'Local', 'is_active'], 'unique' => ['name'],
        'refs' => [['purchase_orders', 'supplier_id'], ['vouchers', 'supplier_id'], ['supplier_invoices', 'supplier_id'], ['supplier_payments', 'supplier_id']]],

    'account-heads' => ['title' => 'Chart of Accounts', 'model' => Models\AccountHead::class, 'order' => 'code', 'perm' => 'gl.manage',
        'hint' => 'Accounts marked System are used by automatic postings (front office, POS, stores, vouchers): they can be renamed or re-coded but not deleted, deactivated or re-typed. Codes: 1xxx assets · 2xxx liabilities · 3xxx equity · 4xxx income · 5xxx–6xxx expenses.',
        'fields' => [
            'code' => ['Account code', 'text', 'required|string|max:12|alpha_dash'], 'name' => ['Account name', 'text', 'required|string|max:100'],
            'type' => ['Type', 'select', 'required|in:asset,liability,equity,income,expense', Models\AccountHead::TYPES],
            'group_name' => ['Group (for statements)', 'text', 'nullable|string|max:60'], 'is_active' => $active,
        ],
        'columns' => ['code', 'name', 'type', 'group_name', 'system_key' => 'System', 'is_active'], 'unique' => ['code', 'name'],
        'refs' => [['gl_lines', 'account_head_id'], ['vouchers', 'account_head_id'], ['payment_modes', 'account_head_id']]],
];
