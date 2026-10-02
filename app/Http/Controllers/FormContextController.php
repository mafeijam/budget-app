<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

/**
 * A form's props without its list, for the Add menu to open the form over any page: each is
 * the formProps() its own page sends, so the dialog is the same one either way.
 */
class FormContextController extends Controller
{
    /** The menu's names, each the form's own page's formProps(). */
    private const FORMS = [
        'transaction' => TransactionController::class,
        'account' => AccountController::class,
        'category' => CategoryController::class,
        'recurring' => RecurringTransactionController::class,
        'transfer' => TransferController::class,
    ];

    public function show(string $form): JsonResponse
    {
        abort_unless(array_key_exists($form, self::FORMS), 404);

        return response()->json(self::FORMS[$form]::formProps());
    }
}
