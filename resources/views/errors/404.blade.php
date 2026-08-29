@include('errors.layout', [
    'statusCode' => 404,
    'heading' => 'Page Not Found',
    'message' => 'Sorry, we could not find the page you are looking for. It may have been moved, removed, or the URL may be incorrect.',
    'details' => 'The requested page could not be located.',
    'nextStep' => 'Return to the ADVS homepage to continue.',
    'errorKey' => 'PAGE_NOT_FOUND',
    'icon' => 'document-magnifying-glass',
])
