<?php

require __DIR__ . '/../vendor/autoload.php';

function assertEqual($expect, $actual)
{
    if ($expect != $actual) {
        echo("\n assert failed");
        echo("\n  expect:\n    " . $expect);
        echo("\n  actual:\n    " . $actual);
        echo("\n");
    } else {
        echo("assert ok\n");
        echo("\n");
    }
}
