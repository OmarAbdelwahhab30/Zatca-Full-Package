<?php

namespace Zatca\Dashboard\Demo;

/** Inserts the sample customers and products once; safe to call repeatedly. */
class DemoSeeder
{
    public static function seed(): void
    {
        if (DemoProduct::query()->exists()) {
            return;
        }

        foreach ([
            ['Wireless Headphones', 150.00], ['USB-C Charging Cable', 25.00], ['Laptop Stand', 90.00],
            ['Mechanical Keyboard', 260.00], ['Monitor 27 inch', 780.00], ['Office Chair', 540.00],
        ] as [$name, $price]) {
            DemoProduct::create(['name' => $name, 'price_excl_vat' => $price, 'vat_rate' => 15]);
        }

        DemoCustomer::create(['type' => 'individual', 'name' => 'Mohammed Al-Harbi']);
        DemoCustomer::create(['type' => 'individual', 'name' => 'Sara Al-Qahtani']);

        // Sample Saudi companies (VAT numbers: 15 digits, starting and ending with 3).
        DemoCustomer::create([
            'type' => 'company', 'name' => 'Gulf Stationery Supplies LLC', 'vat_number' => '399999999800003',
            'cr_number' => '1010987654', 'street' => 'Prince Sultan Street', 'building_number' => '2322',
            'additional_number' => '4325', 'district' => 'Al Murabba', 'city' => 'Riyadh', 'postal_code' => '12613',
        ]);
        DemoCustomer::create([
            'type' => 'company', 'name' => 'Red Sea Technology Co.', 'vat_number' => '310122393500003',
            'cr_number' => '4030123456', 'street' => 'Al Tahlia Street', 'building_number' => '7445',
            'additional_number' => '2210', 'district' => 'Al Rawdah', 'city' => 'Jeddah', 'postal_code' => '23434',
        ]);
    }
}
