<?php

/**
 * Provinces / territories of Pakistan and the cities we deliver to.
 *
 * The storefront reads this list through `ShopSettings.provinces`, so the
 * checkout can offer every province and only the cities that belong to the
 * selected one. `PlaceOrder` validates against exactly the same data, which is
 * why the list lives here instead of being typed into the page.
 *
 * Order matters: it is the order the selects render in.
 *
 * @var array<string, array<int, string>>
 */
return [
    'Punjab' => [
        'Lahore', 'Rawalpindi', 'Faisalabad', 'Multan', 'Gujranwala', 'Sialkot',
        'Bahawalpur', 'Sargodha', 'Sahiwal', 'Rahim Yar Khan', 'Sheikhupura',
        'Jhelum', 'Gujrat', 'Kasur', 'Okara', 'Chiniot', 'Jhang', 'Khanewal',
        'Mianwali', 'Muzaffargarh', 'Dera Ghazi Khan', 'Rajanpur', 'Layyah',
        'Bhakkar', 'Khushab', 'Narowal', 'Mandi Bahauddin', 'Hafizabad',
        'Nankana Sahib', 'Pakpattan', 'Toba Tek Singh', 'Vehari', 'Lodhran',
        'Bahawalnagar', 'Attock', 'Chakwal', 'Talagang', 'Murree', 'Kot Addu',
        'Wazirabad', 'Taunsa', 'Gujar Khan', 'Wah Cantt', 'Kamoke', 'Daska',
        'Sambrial', 'Pasrur', 'Shakargarh', 'Ahmadpur East', 'Uch Sharif',
        'Fort Abbas', 'Haroonabad', 'Mian Channu', 'Kabirwala', 'Jalalpur Pirwala',
        'Shujabad', 'Chaubara', 'Kallar Kahar', 'Bhalwal', 'Kot Momin',
        'Pind Dadan Khan', 'Dina', 'Kharian', 'Sarai Alamgir', 'Chichawatni',
    ],
    'Sindh' => [
        'Karachi', 'Hyderabad', 'Sukkur', 'Larkana', 'Nawabshah', 'Mirpur Khas',
        'Jacobabad', 'Shikarpur', 'Khairpur', 'Dadu', 'Jamshoro', 'Badin',
        'Thatta', 'Sujawal', 'Tharparkar', 'Umerkot', 'Sanghar', 'Ghotki',
        'Kashmore', 'Matiari', 'Tando Allahyar', 'Tando Muhammad Khan',
        'Naushahro Feroze', 'Qambar Shahdadkot', 'Karachi Central',
        'Karachi East', 'Karachi South', 'Karachi West', 'Karachi Malir',
        'Karachi Korangi', 'Keamari',
    ],
    'Khyber Pakhtunkhwa' => [
        'Peshawar', 'Mardan', 'Abbottabad', 'Mansehra', 'Swat (Mingora)',
        'Kohat', 'Bannu', 'Dera Ismail Khan', 'Charsadda', 'Nowshera', 'Swabi',
        'Haripur', 'Malakand', 'Lower Dir', 'Upper Dir', 'Buner', 'Shangla',
        'Battagram', 'Karak', 'Hangu', 'Tank', 'Lakki Marwat', 'Chitral Lower',
        'Chitral Upper', 'Bajaur', 'Mohmand', 'Khyber', 'Kurram', 'Orakzai',
        'North Waziristan', 'South Waziristan', 'Kohistan Lower',
        'Kohistan Upper', 'Kolai Palas', 'Allai',
    ],
    'Balochistan' => [
        'Quetta', 'Gwadar', 'Turbat (Kech)', 'Khuzdar', 'Sibi', 'Loralai',
        'Zhob', 'Chaman', 'Pishin', 'Kalat', 'Mastung', 'Nushki', 'Kharan',
        'Washuk', 'Panjgur', 'Awaran', 'Lasbela (Hub)', 'Jaffarabad',
        'Nasirabad', 'Jhal Magsi', 'Sohbatpur', 'Usta Muhammad', 'Dera Bugti',
        'Kohlu', 'Barkhan', 'Musakhel', 'Duki', 'Harnai', 'Killa Saifullah',
        'Killa Abdullah', 'Ziarat', 'Chagai', 'Surab', 'Lehri',
    ],
    'Islamabad Capital Territory' => [
        'Islamabad',
    ],
    'Gilgit-Baltistan' => [
        'Gilgit', 'Skardu', 'Hunza', 'Nagar', 'Ghanche', 'Shigar', 'Astore',
        'Diamer', 'Ghizer', 'Gupis-Yasin', 'Kharmang', 'Roundu', 'Tangir',
        'Darel',
    ],
    'Azad Jammu and Kashmir' => [
        'Muzaffarabad', 'Mirpur', 'Rawalakot (Poonch)', 'Kotli', 'Bagh',
        'Bhimber', 'Hattian Bala', 'Neelum', 'Haveli', 'Sudhnoti',
    ],
];
