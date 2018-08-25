<?php

include __DIR__ . '/../vendor/autoload.php';

use PiPHP\GPIO\GPIO;
use bviguier\AlarmPi\Hardware\LiquidCrystal;

$gpio = new GPIO();
$rsPin = $gpio->getOutputPin(21);
$enablePin = $gpio->getOutputPin(20);
$d0Pin = $gpio->getOutputPin(16);
$d1Pin = $gpio->getOutputPin(12);
$d2Pin = $gpio->getOutputPin(7);
$d3Pin = $gpio->getOutputPin(8);

$lcd = new LiquidCrystal($rsPin, $enablePin, $d0Pin, $d1Pin, $d2Pin, $d3Pin);

$lcd->begin(16, 2);
$lcd->print("Hello World");