@include('errors.layout', [
    'statusCode' => 408,
    'heading' => 'Request Timeout',
    'message' => 'The request took too long to complete. Please try again.',
    'details' => 'ADVS did not receive the request in time.',
    'nextStep' => 'Refresh the page or return home before starting the action again.',
    'errorKey' => 'REQUEST_TIMEOUT',
    'icon' => 'clock',
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
