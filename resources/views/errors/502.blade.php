@include('errors.layout', [
    'statusCode' => 502,
    'heading' => 'Bad Gateway',
    'message' => 'We are having trouble communicating with the server. Please try again.',
    'details' => 'A service ADVS relies on returned an unexpected response.',
    'nextStep' => 'Give the upstream service a moment, then retry the request.',
    'errorKey' => 'BAD_GATEWAY',
    'icon' => 'cloud-arrow-down',
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
