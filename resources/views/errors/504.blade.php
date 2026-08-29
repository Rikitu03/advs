@include('errors.layout', [
    'statusCode' => 504,
    'heading' => 'Gateway Timeout',
    'message' => 'The server took too long to respond. Please try again.',
    'details' => 'A service ADVS contacted did not respond in time.',
    'nextStep' => 'Retry the request after a short wait, or return home if the page remains unavailable.',
    'errorKey' => 'GATEWAY_TIMEOUT',
    'icon' => 'signal-slash',
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
