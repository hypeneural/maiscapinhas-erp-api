<?php

declare(strict_types=1);

use App\Models\CashClosing;
use App\Models\CashClosingLine;
use App\Models\CashShift;
use App\Models\Store;
use App\Models\StoreUser;
use App\Models\User;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    $this->store = Store::create([
        'name' => 'Test Store',
        'city' => 'Test City',
        'active' => true,
    ]);

    $this->vendedor = User::create([
        'name' => 'Vendedor Test',
        'email' => 'vendedor@example.com',
        'password' => bcrypt('password'),
        'active' => true,
    ]);

    $this->conferente = User::create([
        'name' => 'Conferente Test',
        'email' => 'conferente@example.com',
        'password' => bcrypt('password'),
        'active' => true,
    ]);

    StoreUser::create([
        'store_id' => $this->store->id,
        'user_id' => $this->vendedor->id,
        'role' => 'vendedor',
    ]);

    StoreUser::create([
        'store_id' => $this->store->id,
        'user_id' => $this->conferente->id,
        'role' => 'conferente',
    ]);

    // Create shift with closing
    $this->shift = CashShift::create([
        'store_id' => $this->store->id,
        'date' => now()->format('Y-m-d'),
        'shift_code' => 'M',
        'seller_id' => $this->vendedor->id,
        'status' => 'open',
    ]);

    $this->closing = CashClosing::create([
        'cash_shift_id' => $this->shift->id,
        'status' => 'draft',
        'version' => 1,
    ]);
});

test('submit closing fails when divergence has no justification', function () {
    // Create line with divergence but no justification
    CashClosingLine::create([
        'cash_closing_id' => $this->closing->id,
        'label' => 'Dinheiro',
        'system_value' => 1000.00,
        'real_value' => 950.00,
        'diff_value' => -50.00,
        'justification_text' => null, // No justification!
    ]);

    $response = actingAs($this->vendedor)
        ->postJson("/api/v1/cash/closings/{$this->shift->id}/submit");

    $response->assertStatus(422)
        ->assertJsonPath('errors.lines.0', 'All divergences must have a justification before submitting.');
});

test('submit closing succeeds when all divergences are justified', function () {
    // Create line with divergence AND justification
    CashClosingLine::create([
        'cash_closing_id' => $this->closing->id,
        'label' => 'Dinheiro',
        'system_value' => 1000.00,
        'real_value' => 950.00,
        'diff_value' => -50.00,
        'justification_text' => 'Cliente pagou menos, erro de troco',
    ]);

    $response = actingAs($this->vendedor)
        ->postJson("/api/v1/cash/closings/{$this->shift->id}/submit");

    $response->assertStatus(200)
        ->assertJsonPath('data.status', 'submitted');

    expect($this->closing->fresh()->status)->toBe('submitted');
    expect($this->shift->fresh()->status)->toBe('pending');
});

test('submit closing succeeds with no divergences', function () {
    // Create line with NO divergence
    CashClosingLine::create([
        'cash_closing_id' => $this->closing->id,
        'label' => 'Dinheiro',
        'system_value' => 1000.00,
        'real_value' => 1000.00,
        'diff_value' => 0,
        'justification_text' => null,
    ]);

    $response = actingAs($this->vendedor)
        ->postJson("/api/v1/cash/closings/{$this->shift->id}/submit");

    $response->assertStatus(200)
        ->assertJsonPath('data.status', 'submitted');
});

test('approve closing requires conferente or higher role', function () {
    $this->closing->update(['status' => 'submitted']);

    $response = actingAs($this->vendedor)
        ->postJson("/api/v1/cash/closings/{$this->shift->id}/approve");

    $response->assertStatus(403);
});

test('conferente can approve submitted closing', function () {
    $this->closing->update(['status' => 'submitted']);

    $response = actingAs($this->conferente)
        ->postJson("/api/v1/cash/closings/{$this->shift->id}/approve");

    $response->assertStatus(200)
        ->assertJsonPath('data.status', 'approved');

    expect($this->closing->fresh()->status)->toBe('approved');
    expect($this->shift->fresh()->status)->toBe('closed');
});

test('reject closing requires reason', function () {
    $this->closing->update(['status' => 'submitted']);

    $response = actingAs($this->conferente)
        ->postJson("/api/v1/cash/closings/{$this->shift->id}/reject");

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['reason']);
});

test('conferente can reject submitted closing with reason', function () {
    $this->closing->update(['status' => 'submitted']);

    $response = actingAs($this->conferente)
        ->postJson("/api/v1/cash/closings/{$this->shift->id}/reject", [
            'reason' => 'Valores não conferem com o relatório do PDV',
        ]);

    $response->assertStatus(200)
        ->assertJsonPath('data.status', 'rejected');

    expect($this->closing->fresh()->status)->toBe('rejected');
    expect($this->shift->fresh()->status)->toBe('open');
});

test('cannot approve already approved closing', function () {
    $this->closing->update(['status' => 'approved']);

    $response = actingAs($this->conferente)
        ->postJson("/api/v1/cash/closings/{$this->shift->id}/approve");

    $response->assertStatus(409);
});

test('cannot submit already submitted closing', function () {
    $this->closing->update(['status' => 'submitted']);

    $response = actingAs($this->vendedor)
        ->postJson("/api/v1/cash/closings/{$this->shift->id}/submit");

    $response->assertStatus(409);
});
