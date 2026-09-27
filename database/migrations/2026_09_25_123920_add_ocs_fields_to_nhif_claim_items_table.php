<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nhif_claim_items', function (Blueprint $table) {
            $table->tinyInteger('item_type_id')->nullable()->after('nhif_item_name');
            $table->string('approval_ref_no')->nullable()->after('nhif_authorization_no');
            $table->string('other_details')->nullable()->after('approval_ref_no');
        });
    }

    public function down(): void
    {
        Schema::table('nhif_claim_items', function (Blueprint $table) {
            $table->dropColumn(['item_type_id', 'approval_ref_no', 'other_details']);
        });
    }
};
