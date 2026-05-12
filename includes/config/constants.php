<?php
// Additional constants
define('ALERT_LEVELS', [
    'info' => ['color' => 'blue', 'sms' => false],
    'warning' => ['color' => 'yellow', 'sms' => true],
    'urgent' => ['color' => 'orange', 'sms' => true],
    'emergency' => ['color' => 'red', 'sms' => true],
]);
define('INCIDENT_TYPES', ['flood', 'fire', 'earthquake', 'landslide', 'drought', 'storm', 'other']);
define('RESOURCE_TYPES', ['food', 'water', 'medicine', 'shelter', 'clothing', 'rescue', 'transport', 'other']);
define('SKILLS_LIST', ['medical', 'rescue', 'logistics', 'communication', 'counseling', 'driving', 'construction']);
?>