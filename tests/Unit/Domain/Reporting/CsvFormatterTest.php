<?php

use App\Domain\Reporting\CsvFormatter;

it('prefixes a quote to text that a spreadsheet would read as a formula', function (string $value, string $expected) {
    expect(CsvFormatter::text($value))->toBe($expected);
})->with([
    'equals' => ['=SUM(A1)', "'=SUM(A1)"],
    'plus' => ['+44 7700 900123', "'+44 7700 900123"],
    'minus' => ['-Discovery', "'-Discovery"],
    'at sign' => ['@user', "'@user"],
    'tab' => ["\tcmd", "'\tcmd"],
    'carriage return, which also needs quoting' => ["\rcmd", "\"'\rcmd\""],
]);

it('leaves other text alone', function (string $value, string $expected) {
    expect(CsvFormatter::text($value))->toBe($expected);
})->with([
    'plain value' => ['Plain value', 'Plain value'],
    'empty string' => ['', ''],
    'trigger character that is not first' => ['a=b', 'a=b'],
    'leading space' => [' =1', ' =1'],
]);

it('quotes neutralised text the same way as field()', function () {
    expect(CsvFormatter::text('=HYPERLINK("x")'))->toBe('"\'=HYPERLINK(""x"")"')
        ->and(CsvFormatter::text('=A,B'))->toBe('"\'=A,B"')
        ->and(CsvFormatter::text('Acme, Inc'))->toBe(CsvFormatter::field('Acme, Inc'));
});
