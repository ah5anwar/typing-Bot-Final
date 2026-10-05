<?php
// bot/Handlers.php — Conflict-free loader
if (!class_exists('OnboardingHandler')) require_once __DIR__.'/OnboardingHandler.php';
if (!class_exists('MenuHandler'))       require_once __DIR__.'/MenuHandler.php';
if (!class_exists('TrackingHandler'))   require_once __DIR__.'/TrackingHandler.php';
