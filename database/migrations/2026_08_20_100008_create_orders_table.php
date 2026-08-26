<?php

use App\Enums\OrderStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();

            // Generated client-side before the order ever reaches the server.
            // The unique index is what makes retrying a sync safe: firstOrCreate
            // on this column collapses duplicate flushes into one order.
            $table->string('client_uuid', 36)->unique();

            // Server-generated at sync time: {store}-{register}-{YYMMDD}-{seq}
            $table->string('order_no')->unique();

            $table->foreignId('store_id')->constrained()->restrictOnDelete();
            $table->foreignId('register_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('cashier_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();

            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('discount_amount', 12, 2)->default(0);
            // total = subtotal - discount_amount. Nothing is added on top.
            $table->decimal('total', 12, 2)->default(0);

            // paid_amount = SUM(payments.amount); change_amount = paid - total, cash only.
            $table->decimal('paid_amount', 12, 2)->default(0);
            $table->decimal('change_amount', 12, 2)->default(0);

            $table->enum('status', OrderStatus::values())->default(OrderStatus::Completed->value);

            $table->timestamp('synced_at')->nullable();

            // When the sale actually happened on the tablet, which may be hours
            // before it reached the server. Reports must use this, not created_at.
            $table->timestamp('created_offline_at')->nullable();

            $table->timestamps();

            $table->index(['store_id', 'created_at']);
            $table->index(['cashier_id', 'created_at']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
