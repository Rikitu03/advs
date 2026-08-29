@include('errors.layout', [
    'statusCode' => 500,
    'heading' => 'Something Went Wrong',
    'message' => 'Something went wrong on our side. Please try again later.',
    'details' => 'ADVS could not complete the request because of an internal issue.',
    'nextStep' => 'Return to ADVS and retry once the page is available again.',
    'errorKey' => 'SOMETHING_WENT_WRONG',
    'icon' => 'server-stack',
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
