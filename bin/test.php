<?php

include __DIR__ . '/../vendor/autoload.php';

use PiPHP\GPIO\GPIO;

$gpio = new GPIO();
$pin14 = $gpio->getOutputPin(14);

echo "Turning on pin 14\n";
$pin14->setValue(\PiPHP\GPIO\Pin\PinInterface::VALUE_HIGH);

echo "Sleeping!\n";
sleep(3);

echo "Turning off pin 14\n";
$pin14->setValue(\PiPHP\GPIO\Pin\PinInterface::VALUE_LOW);

echo "End\n";