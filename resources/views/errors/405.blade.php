@include('errors.layout', [
    'statusCode' => 405,
    'heading' => 'Method Not Allowed',
    'message' => 'This action is not supported for this page.',
    'details' => 'The page exists, but it does not accept this kind of request.',
    'nextStep' => 'Return to the page and use one of the available actions shown in the interface.',
    'errorKey' => 'METHOD_NOT_ALLOWED',
    'icon' => 'no-symbol',
])
