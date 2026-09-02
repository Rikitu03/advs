@include('errors.layout', [
    'statusCode' => 503,
    'heading' => 'Service Unavailable',
    'message' => 'The service is temporarily unavailable. Please try again in a moment.',
    'details' => 'ADVS is temporarily unable to serve this request.',
    'nextStep' => 'Wait a moment before retrying while ADVS finishes the interrupted service work.',
    'errorKey' => 'SERVICE_UNAVAILABLE',
    'icon' => 'wrench-screwdriver',
    'primaryAction' => [
        'label' => 'Try Again',
        'kind' => 'reload',
        'icon' => 'arrow-path',
    ],
    'secondaryAction' => [
        'label' => 'Back to Home',
        'href' => route('home'),
        'icon' => 'home',
    ],
])
