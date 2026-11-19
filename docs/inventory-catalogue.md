# Categories and units

Manage these features from **Catalogue → Categories** and **Catalogue → Units**.

## Categories and subcategories

Create a top-level category with no parent, or choose a parent to create a subcategory, such as **Drinks → Soft drinks**. Products can belong to either level. Selecting a parent in the product list, POS or public menu also includes its subcategories.

Two levels are supported. A category with children cannot become a subcategory, and categories containing products or children cannot be deleted.

## Base units and packs

Create reusable units with a name and symbol, then choose a product's **Base unit**. Existing unit labels are registered automatically during migration, preserving quantities and custom labels.

Stock uses whole base units. For smaller measurements, choose grams or milliliters. Decimal stock quantities are not supported.

Add a pack size on the product form, choose its **Pack unit**, and enter how many base units it **Holds** plus its selling price. For example:

- Base unit: bottle.
- Pack: carton, holds 24 bottles.
- Selling two cartons removes 48 bottles from the same stock.

Conversion quantities belong to the product: different products may have different quantities per carton. The optional **Counted in cases of** setting remains available for inventory counting without creating a sellable pack.

A base unit cannot change once stock, movements, sales or pack sizes have been recorded. A unit in use can be renamed, but its symbol cannot change and it cannot be deleted. These restrictions protect the meaning of existing stock records.

Units have their own staff permission with view, create, update and delete actions. Existing product clients may continue sending a unit string; the unit is registered and linked automatically.

## Applying the update

Run `php artisan migrate` on each installation before using the updated screens. The migration adds category parents and managed units; it does not convert existing stock quantities. Product variants are outside this change.
