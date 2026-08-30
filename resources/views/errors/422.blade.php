@include('errors.layout', [
    'statusCode' => 422,
    'heading' => 'Unable to Process Request',
    'message' => 'We could not process the information you submitted.',
    'details' => 'Some submitted details need correction before ADVS can continue.',
    'nextStep' => 'Go back, check the highlighted fields, then submit the form again.',
    'errorKey' => 'UNPROCESSABLE_ENTITY',
    'icon' => 'exclamation-triangle',
    'primaryAction' => [
        'label' => 'Go Back',
        'kind' => 'back',
        'icon' => 'arrow-uturn-left',
    ],
    'secondaryAction' => [
        'label' => 'Back to Home',
        'href' => route('home'),
        'icon' => 'home',
    ],
])
