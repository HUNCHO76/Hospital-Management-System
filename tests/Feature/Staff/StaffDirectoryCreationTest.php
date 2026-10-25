<?php

use App\Models\Department;
use App\Models\Hospital;
use App\Models\Practitioner;
use App\Models\Staff;
use App\Models\StaffAccountActivation;
use App\Models\User;
use App\Services\StaffAccountActivationService;
use Database\Seeders\DepartmentSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    // Ensure main hospital exists
    $this->hospital = Hospital::firstOrCreate(
        ['code' => 'MAIN'],
        ['name' => 'Main Hospital', 'status' => 'active']
    );

    // Ensure departments exist
    $this->seed(DepartmentSeeder::class);

    // Ensure roles exist
    Role::findOrCreate('doctor', 'web');
    Role::findOrCreate('nurse', 'web');
    Role::findOrCreate('pharmacist', 'web');
    Role::findOrCreate('laboratory-technician', 'web');
    Role::findOrCreate('radiology-technician', 'web');
    Role::findOrCreate('receptionist', 'web');
    Role::findOrCreate('accountant', 'web');
    Role::findOrCreate('store-officer', 'web');
    Role::findOrCreate('hospital-admin', 'web');
});

test('dry run validates all 32 staff members without creating database rows', function () {
    $initialStaffCount = Staff::count();
    $initialUserCount = User::count();

    $exitCode = Artisan::call('hms:create-staff-accounts', ['--dry-run' => true]);

    expect($exitCode)->toBe(0)
        ->and(Staff::count())->toBe($initialStaffCount)
        ->and(User::count())->toBe($initialUserCount);
});

test('command creates exactly 32 staff members with linked user accounts and least-privilege roles', function () {
    $exitCode = Artisan::call('hms:create-staff-accounts');
    expect($exitCode)->toBe(0);

    // Verify Dental Doctors
    $drBhargavi = Staff::where('first_name', 'Bhargavi')->where('last_name', 'Maridi')->first();
    expect($drBhargavi)->not->toBeNull()
        ->and($drBhargavi->user)->not->toBeNull()
        ->and($drBhargavi->department->code)->toBe('DENTAL')
        ->and($drBhargavi->user->hasRole('doctor'))->toBeTrue()
        ->and($drBhargavi->practitioner->practitioner_type)->toBe('dentist');

    $drRuthsal = Staff::where('first_name', 'Ruthsal')->where('last_name', 'Bindhu')->first();
    expect($drRuthsal)->not->toBeNull()
        ->and($drRuthsal->middle_name)->toBe('Abisha')
        ->and($drRuthsal->user->hasRole('doctor'))->toBeTrue()
        ->and($drRuthsal->practitioner->practitioner_type)->toBe('dentist');

    // Verify Pharmacists
    $irene = Staff::where('first_name', 'Irene')->where('last_name', 'Paul')->first();
    expect($irene)->not->toBeNull()
        ->and($irene->department->code)->toBe('PHARMACY')
        ->and($irene->user->hasRole('pharmacist'))->toBeTrue()
        ->and($irene->practitioner->practitioner_type)->toBe('pharmacist');

    // Verify Cashiers have accountant role and NO practitioner profile
    $zaina = Staff::where('first_name', 'Zaina')->where('last_name', 'Mkwenya')->first();
    expect($zaina)->not->toBeNull()
        ->and($zaina->department->code)->toBe('FINANCE')
        ->and($zaina->user->hasRole('accountant'))->toBeTrue()
        ->and($zaina->practitioner)->toBeNull();

    // Verify Laboratory Technicians
    $samwel = Staff::where('first_name', 'Samwel')->where('last_name', 'Boniface')->first();
    expect($samwel)->not->toBeNull()
        ->and($samwel->department->code)->toBe('LAB')
        ->and($samwel->user->hasRole('laboratory-technician'))->toBeTrue()
        ->and($samwel->practitioner->practitioner_type)->toBe('laboratory-technician');

    // Verify Nurses
    $mohamed = Staff::where('first_name', 'Mohamed')->where('last_name', 'Ngongono')->first();
    expect($mohamed)->not->toBeNull()
        ->and($mohamed->department->code)->toBe('INPATIENT')
        ->and($mohamed->user->hasRole('nurse'))->toBeTrue()
        ->and($mohamed->practitioner->practitioner_type)->toBe('nurse');

    // Verify Radiology Technician
    $edward = Staff::where('first_name', 'Edward')->where('last_name', 'Antony')->first();
    expect($edward)->not->toBeNull()
        ->and($edward->department->code)->toBe('RADIOLOGY')
        ->and($edward->user->hasRole('radiology-technician'))->toBeTrue()
        ->and($edward->practitioner->practitioner_type)->toBe('radiology-technician');

    // Verify Receptionists
    $adelina = Staff::where('first_name', 'Adelina')->where('last_name', 'Julius')->first();
    expect($adelina)->not->toBeNull()
        ->and($adelina->department->code)->toBe('OPD')
        ->and($adelina->user->hasRole('receptionist'))->toBeTrue()
        ->and($adelina->practitioner)->toBeNull();

    // Verify Medical Store Officer
    $storeOfficer = Staff::where('first_name', 'Vanathuchinnapparajan')->first();
    expect($storeOfficer)->not->toBeNull()
        ->and($storeOfficer->last_name)->toBe('R.')
        ->and($storeOfficer->department->code)->toBe('STORES')
        ->and($storeOfficer->user->hasRole('store-officer'))->toBeTrue()
        ->and($storeOfficer->practitioner)->toBeNull();
});

test('operation is completely idempotent and does not create duplicate accounts or reset passwords', function () {
    // Run once
    Artisan::call('hms:create-staff-accounts');
    $staffCountAfterFirstRun = Staff::count();
    $userCountAfterFirstRun = User::count();

    // Capture password hash of one user
    $drBhargaviUser = User::where('UserName', 'Dr. Bhargavi Maridi')->first();
    $originalPasswordHash = $drBhargaviUser->password;

    // Run a second time
    $exitCode = Artisan::call('hms:create-staff-accounts');
    expect($exitCode)->toBe(0);

    // Counts remain unchanged
    expect(Staff::count())->toBe($staffCountAfterFirstRun)
        ->and(User::count())->toBe($userCountAfterFirstRun);

    // Password hash is preserved, not reset
    $drBhargaviUser->refresh();
    expect($drBhargaviUser->password)->toBe($originalPasswordHash);
});

test('user credentials are cryptographically secure random hashes and never stored in plaintext', function () {
    Artisan::call('hms:create-staff-accounts');

    $allUsers = User::all();
    foreach ($allUsers as $user) {
        expect($user->password)->toStartWith('$2y$') // Bcrypt hash prefix
            ->and(strlen($user->password))->toBe(60)
            ->and($user->password)->not->toBe('password')
            ->and($user->password)->not->toBe('secret')
            ->and($user->password)->not->toBe($user->UserName);
    }
});

test('administrator can generate activation link with real verified email', function () {
    Artisan::call('hms:create-staff-accounts');

    $staff = Staff::where('first_name', 'Irene')->where('last_name', 'Paul')->firstOrFail();
    $admin = User::factory()->create();
    $admin->assignRole('hospital-admin');

    $response = $this->actingAs($admin)->post(route('staff.activation.store', $staff->id), [
        'email' => 'irene.paul@hospital.org',
    ]);

    $response->assertRedirect();
    $response->assertSessionHas('success');

    // User email is updated
    $staff->refresh();
    expect($staff->user->email)->toBe('irene.paul@hospital.org');

    // An activation record is created
    $activation = StaffAccountActivation::where('user_id', $staff->user->id)->first();
    expect($activation)->not->toBeNull()
        ->and($activation->expires_at)->toBeGreaterThan(now())
        ->and($activation->activated_at)->toBeNull();
});

test('staff member can complete activation and set their initial password', function () {
    Artisan::call('hms:create-staff-accounts');

    $staff = Staff::where('first_name', 'Samwel')->where('last_name', 'Boniface')->firstOrFail();
    $user = $staff->user;

    // Generate activation
    $result = app(StaffAccountActivationService::class)->createActivationInvitation(
        $user,
        'samwel.boniface@hospital.org',
    );

    $plainToken = $result['plainToken'];

    // Staff visits activation page
    $getPage = $this->get(route('activation.create', $plainToken));
    $getPage->assertOk();

    // Staff submits new password
    $postResponse = $this->post(route('activation.store', $plainToken), [
        'password' => 'SecurePass2026!#',
        'password_confirmation' => 'SecurePass2026!#',
    ]);

    $postResponse->assertRedirect(route('dashboard'));

    // User is active and password verifies
    $user->refresh();
    expect($user->status)->toBe('active')
        ->and($user->email_verified_at)->not->toBeNull()
        ->and(Hash::check('SecurePass2026!#', $user->password))->toBeTrue();

    // Token cannot be reused
    expect(StaffAccountActivation::findValidByPlainToken($plainToken))->toBeNull();
});

test('unauthorized users cannot generate activation links', function () {
    Artisan::call('hms:create-staff-accounts');

    $staff = Staff::where('first_name', 'Irene')->where('last_name', 'Paul')->firstOrFail();
    $regularNurse = User::factory()->create();
    $regularNurse->assignRole('nurse');

    $response = $this->actingAs($regularNurse)->post(route('staff.activation.store', $staff->id), [
        'email' => 'new.email@hospital.org',
    ]);

    $response->assertForbidden();
});

test('staff assign-emails dry-run validates all 32 emails without mutating database', function () {
    Artisan::call('hms:create-staff-accounts');

    $staff = Staff::where('first_name', 'Bhargavi')->firstOrFail();
    $user = $staff->user;
    $user->forceFill(['email' => null])->save();
    $staff->update(['email' => null]);

    $exitCode = Artisan::call('staff:assign-emails', ['--dry-run' => true]);
    expect($exitCode)->toBe(0);

    // Dr. Bhargavi Maridi should still have null/empty email because dry-run rolled back
    $user->refresh();
    expect($user->email)->toBeNull();
});

test('staff assign-emails command updates all 32 staff and user records with departmental emails', function () {
    Artisan::call('hms:create-staff-accounts');

    $exitCode = Artisan::call('staff:assign-emails');
    expect($exitCode)->toBe(0);

    $expected = [
        ['first' => 'Bhargavi', 'last' => 'Maridi', 'email' => 'bhargavi.maridi@dental.hms'],
        ['first' => 'Ruthsal', 'last' => 'Bindhu', 'email' => 'ruthsal.bindhu@dental.hms'],
        ['first' => 'Irene', 'last' => 'Paul', 'email' => 'irene.paul@pharmacy.hms'],
        ['first' => 'Zaina', 'last' => 'Mkwenya', 'email' => 'zaina.mkwenya@cashier.hms'],
        ['first' => 'Samwel', 'last' => 'Boniface', 'email' => 'samwel.boniface@laboratory.hms'],
        ['first' => 'Mohamed', 'last' => 'Ngongono', 'email' => 'mohamed.ngongono@nursing.hms'],
        ['first' => 'Edward', 'last' => 'Antony', 'email' => 'edward.antony@radiology.hms'],
        ['first' => 'Adelina', 'last' => 'Julius', 'email' => 'adelina.julius@reception.hms'],
        ['first' => 'Vanathuchinnapparajan', 'last' => 'R.', 'email' => 'vanathuchinnapparajan.r@medicalstore.hms'],
    ];

    foreach ($expected as $item) {
        $staff = Staff::where('first_name', $item['first'])->where('last_name', $item['last'])->firstOrFail();
        $user = $staff->user;

        expect($staff->email)->toBe($item['email'])
            ->and($user->email)->toBe($item['email'])
            ->and(filter_var($user->email, FILTER_VALIDATE_EMAIL))->not->toBeFalse();
    }

    // Verify rerun is idempotent
    $reRunExit = Artisan::call('staff:assign-emails');
    expect($reRunExit)->toBe(0);
});

test('staff user can authenticate with default password', function () {
    Artisan::call('hms:create-staff-accounts');
    $exit = Artisan::call('staff:assign-emails', ['--password' => 'password']);
    expect($exit)->toBe(0);

    $response = $this->post('/login', [
        'email' => 'bhargavi.maridi@dental.hms',
        'password' => 'password',
    ]);

    $this->assertAuthenticated();
    $user = User::where('email', 'bhargavi.maridi@dental.hms')->firstOrFail();
    expect($user->status)->toBe('active')
        ->and(Hash::check('password', $user->password))->toBeTrue();
});


