<?php

declare(strict_types=1);

use Blue\Core\Database\Connection;
use Blue\Core\Database\Migration;
use Blue\Core\Database\Schema;

/**
 * 0.47.0 (R8-2 · DEC-116): the registered address of an employee split into the parts the annual withholding tax form
 * (P.N.D. 1A) asks for — house number, building, alley, road, sub-district, district, province, postcode. The free-text
 * addresses stay as they are; this one is kept by the employee or HR next to them.
 */

return new class extends Migration {
    public function description(): string
    {
        return 'Employee registered address in parts (for tax forms)';
    }

    public function up(Connection $db): void
    {
        $o = Schema::TABLE_OPTIONS;
        $std = Schema::standardColumns();
        $db->exec("CREATE TABLE IF NOT EXISTS employee_tax_addresses (
            {$std},
            employee_id BIGINT UNSIGNED NOT NULL,
            house_no VARCHAR(30) NOT NULL,
            building VARCHAR(80) NULL,
            alley VARCHAR(80) NULL,
            road VARCHAR(80) NULL,
            subdistrict VARCHAR(80) NOT NULL,
            district VARCHAR(80) NOT NULL,
            province VARCHAR(40) NOT NULL,
            postcode CHAR(5) NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_emp_tax_addr_public (public_id),
            UNIQUE KEY uq_emp_tax_addr_employee (employee_id),
            CONSTRAINT fk_emp_tax_addr_employee FOREIGN KEY (employee_id) REFERENCES employees (id)
        ) {$o}");
    }
};
