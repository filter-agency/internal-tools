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

it('strips the neutralising quote when reading text back', function (string $value, string $expected) {
    expect(CsvFormatter::fromText($value))->toBe($expected);
})->with([
    'equals' => ["'=SUM(A1)", '=SUM(A1)'],
    'minus' => ["'- fixed login", '- fixed login'],
    'tab' => ["'\tcmd", "\tcmd"],
    'apostrophe before ordinary text' => ["'quoted", "'quoted"],
    'lone apostrophe' => ["'", "'"],
    'plain value' => ['Plain value', 'Plain value'],
    'empty string' => ['', ''],
]);

it('round-trips text through a CSV cell', function (string $original) {
    $cell = str_getcsv(CsvFormatter::text($original), ',', '"', '')[0];

    expect(CsvFormatter::fromText($cell))->toBe($original);
})->with([
    '=HYPERLINK("x")', '- bullet', '+Plus', '@user', 'Acme, Inc', 'Plain value',
]);

it('quotes neutralised text the same way as field()', function () {
    expect(CsvFormatter::text('=HYPERLINK("x")'))->toBe('"\'=HYPERLINK(""x"")"')
        ->and(CsvFormatter::text('=A,B'))->toBe('"\'=A,B"')
        ->and(CsvFormatter::text('Acme, Inc'))->toBe(CsvFormatter::field('Acme, Inc'));
});
