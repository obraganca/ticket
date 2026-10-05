<?php

use App\Rules\ValidCpf;

it('accepts a valid cpf', function () {
    expect(ValidCpf::isValid('52998224725'))->toBeTrue();
});

it('rejects a cpf with all repeated digits', function () {
    expect(ValidCpf::isValid('11111111111'))->toBeFalse();
});

it('rejects a cpf with wrong check digits', function () {
    expect(ValidCpf::isValid('52998224726'))->toBeFalse();
});

it('rejects a cpf with the wrong length', function () {
    expect(ValidCpf::isValid('123'))->toBeFalse();
});
