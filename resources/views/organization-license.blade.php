<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Connect MediFlow license | MediSphere</title>
    <link rel="stylesheet" href="{{ asset('vendor/kernelbridge-licensing/activation.css') }}">
    @livewireStyles
</head>
<body>
    <livewire:organization-license-link :organization-id="$organization->id" />
    @livewireScripts
</body>
</html>
