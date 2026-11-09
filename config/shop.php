<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Order statuses
    |--------------------------------------------------------------------------
    |
    | Stored values are kept as they are in the database so existing data stays
    | valid, while the labels are the human friendly names shown in emails and
    | in the admin panel.
    |
    */

    'order_statuses' => [
        'pending' => 'Pending',
        'on-way' => 'On the way',
        'recieve' => 'Delivered',
    ],

    /*
    |--------------------------------------------------------------------------
    | Payment methods
    |--------------------------------------------------------------------------
    |
    | Cash on delivery and bank transfer cover almost every Pakistani order.
    | A wallet (Easypaisa / JazzCash) option is also available. Which of them
    | are offered is controlled from the admin panel via ShopSettings.
    |
    */

    'payment_methods' => [
        'cod' => 'Cash on delivery',
        'bank' => 'Bank transfer',
        'wallet' => 'Easypaisa / JazzCash',
    ],

    'payment_statuses' => [
        'unpaid' => 'Unpaid',
        'paid' => 'Paid',
        'refunded' => 'Refunded',
    ],

    /*
    |--------------------------------------------------------------------------
    | Product types
    |--------------------------------------------------------------------------
    |
    | The store sells prepared clothing and perfume. The type decides which
    | detail fields and which variant options (sizes/colours vs volumes) the
    | admin panel asks for.
    |
    */

    'product_types' => [
        'clothing' => 'Clothing',
        'perfume' => 'Perfume',
    ],

    /** Default size list offered when adding clothing. Admin can override. */
    'clothing_sizes' => ['XS', 'S', 'M', 'L', 'XL', 'XXL'],

    /** Default volume list offered when adding perfume. */
    'perfume_volumes' => ['30 ml', '50 ml', '100 ml'],

    /*
    |--------------------------------------------------------------------------
    | Pakistan shipping
    |--------------------------------------------------------------------------
    |
    | These are only fallbacks. The live values come from the `settings` table
    | and are editable in the admin panel.
    |
    */

    'currency' => [
        'code' => 'PKR',
        'symbol' => 'Rs',
        'decimals' => 0,
    ],

    'shipping_fee' => (float) env('SHOP_SHIPPING_FEE', 250),

    /**
     * Provinces and their main cities, used by the checkout address form and
     * the admin address displays.
     *
     * @var array<string, array<int, string>>
     */
    'provinces' => [
        'Punjab' => [
            'Lahore', 'Faisalabad', 'Rawalpindi', 'Multan', 'Gujranwala',
            'Sialkot', 'Bahawalpur', 'Sargodha', 'Sahiwal', 'Rahim Yar Khan',
        ],
        'Sindh' => ['Karachi', 'Hyderabad', 'Sukkur', 'Larkana', 'Nawabshah'],
        'Khyber Pakhtunkhwa' => ['Peshawar', 'Mardan', 'Abbottabad', 'Mingora', 'Kohat'],
        'Balochistan' => ['Quetta', 'Gwadar', 'Turbat', 'Khuzdar'],
        'Islamabad Capital Territory' => ['Islamabad'],
        'Gilgit-Baltistan' => ['Gilgit', 'Skardu'],
        'Azad Jammu and Kashmir' => ['Muzaffarabad', 'Mirpur'],
    ],
];
