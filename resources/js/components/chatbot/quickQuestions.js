export const quickQuestions = {
    main: [
        {
            label: 'Payment options',
            question: 'What payment methods can I use?',
        },
        {
            label: 'Delivery fees & estimates',
            question: 'How are delivery fees and delivery times estimated?',
        },
        {
            label: 'My order status',
            question: 'How can I check my order status?',
        },
        { label: 'Cancel an order', question: 'How do I cancel my order?' },
        { label: 'Returns & refunds', menu: 'returns' },
        { label: 'PC Builder help', menu: 'builder' },
    ],
    returns: [
        { label: 'Request a return', question: 'How do I request a return?' },
        { label: 'Check return status', question: 'My return status' },
        { label: 'Check refund status', question: 'My refund status' },
    ],
    builder: [
        { label: 'Getting started', question: 'How do I use PC Builder?' },
        {
            label: 'Select components',
            question: 'How do I select components in PC Builder?',
        },
        { label: 'Saved builds', question: 'How do saved PC builds work?' },
        {
            label: 'Predefined templates',
            question: 'How do PC Builder templates work?',
        },
        {
            label: 'Compatibility results',
            question: 'Where can I view PC Builder compatibility results?',
        },
    ],
};
