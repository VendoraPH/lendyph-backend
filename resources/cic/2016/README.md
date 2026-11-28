# CIC CSDF 2016 layouts and domains

- `layouts.json`: every record's fields (HD, ID, BD, SL, CI, CN, CC, NE, FT), in file order: id, name, type (X text, N number, D date DDMMYYYY), classification (M, D, NM), maxLength, domain, fixed value and the workbook's rule text for Dependent fields. 612 fields.
- `domains.json`: the CIC code lists, each code with its label (null where the workbook has none). Contract type and status lists differ per record, hence the `CI:` style prefixes.

Where the data came from: CIC's 2016 CSDF workbooks (`data format.xlsx` for the layouts, `Fields in Excel.xlsx` for the code lists), as encoded in lendyph-frontend PR #699 (`src/lib/cic/layouts.generated.ts` and `domains.generated.ts`). Don't edit the JSON by hand; regenerate it from those files:

    php resources/cic/2016/generate.php path/to/layouts.generated.ts path/to/domains.generated.ts

`App\Services\Cic\CicLayouts` and `App\Services\Cic\CicDomains` read them, and `tests/Feature/Cic/Foundation/CicLayoutsTest.php` pins the counts to the CIC task prompt's §1.2 table.
