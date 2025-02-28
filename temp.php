<?php
date_default_timezone_set('Asia/Karachi');

// 1. Create initial time
$initialTime = DateTime::createFromFormat('d M h:i a', '28 Feb 6:52 pm');
echo "Initial Time: " . $initialTime->format('j M g:i a') . "<br>";

// 2. Add 1 day
$futureTime = clone $initialTime;
$futureTime->modify('+1 day');
echo "After 1 Day: " . $futureTime->format('j M g:i a') . "<br>";

// 3. Calculate time remaining
$now = new DateTime();
$diff = $now->diff($futureTime);

if ($diff->invert) {
    $message = "The time has already passed.";
} else {
    $hours = $diff->days * 24 + $diff->h;
    $minutes = $diff->i;
    $message = "Hours left until " . $futureTime->format('j M g:i a') . ": " 
             . $hours . " hrs. " . $minutes . " min. left";
}

echo $message;
?>