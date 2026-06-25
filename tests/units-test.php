<?php
/**
 * Офлайн-тест чистой логики Units (§5.6). Запуск: php tests/units-test.php
 * Не требует OpenCart — проверяет конверсию г/мм → единицы классов магазина.
 */
require __DIR__ . '/../onecatalogimport/classes/Units.php';

$fail = 0;
function check($label, $got, $want, &$fail)
{
    $ok = abs((float) $got - (float) $want) < 0.0001;
    echo ($ok ? '  ok  ' : '  FAIL ') . $label . " (got=$got want=$want)\n";
    if (!$ok) { $fail++; }
}

// Вес: граммы → целевая единица.
check('1000 g → kg', OneCatalogUnits::weight(1000, 'kg'), 1.0, $fail);
check('1000 g → g', OneCatalogUnits::weight(1000, 'g'), 1000.0, $fail);
check('1000 g → lb', OneCatalogUnits::weight(1000, 'lb'), 2.20462, $fail);
check('unknown weight unit → passthrough', OneCatalogUnits::weight(750, '???'), 750.0, $fail);

// Размеры: мм → целевая единица.
check('1200 mm → cm', OneCatalogUnits::length(1200, 'cm'), 120.0, $fail);
check('1200 mm → mm', OneCatalogUnits::length(1200, 'mm'), 1200.0, $fail);
check('25.4 mm → in', OneCatalogUnits::length(25.4, 'in'), 1.0, $fail);
check('1000 mm → m', OneCatalogUnits::length(1000, 'm'), 1.0, $fail);

// Приведение к базе из единицы источника.
check('2 kg → 2000 g (base)', OneCatalogUnits::toBaseWeight(2, 'kg'), 2000.0, $fail);
check('120 cm → 1200 mm (base)', OneCatalogUnits::toBaseLength(120, 'cm'), 1200.0, $fail);
check('5 in → 127 mm (base)', OneCatalogUnits::toBaseLength(5, 'in'), 127.0, $fail);

echo $fail === 0 ? "\nALL PASS\n" : "\n$fail FAILED\n";
exit($fail === 0 ? 0 : 1);
