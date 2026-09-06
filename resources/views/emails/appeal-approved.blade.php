<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Ban Appeal Approved</title>
</head>
<body>
    <h1>Hello {{ $name }}</h1>
    <p>Your ban appeal has been <strong>approved</strong>.</p>
    @if ($note)
        <p>Admin note: {{ $note }}</p>
    @endif
    <p>Please allow a few minutes for the ban to be lifted by our team.</p>
</body>
</html>