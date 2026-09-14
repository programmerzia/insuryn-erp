<?php

declare(strict_types=1);

use App\Http\People\EmployeesPageController;
use App\Http\People\PayrollRunsPageController;
use App\Http\People\PayrollSettingsPageController;
use Illuminate\Support\Facades\Route;

// People and Payroll MVP (addendum §B.9–§B.11). Required from routes/web.php. Controllers authorize per area; money movements get the journal preview.
Route::middleware('auth')->prefix('people')->group(function (): void {
    Route::get('employees', [EmployeesPageController::class, 'index']);
    Route::post('employees', [EmployeesPageController::class, 'store']);
    Route::get('employees/{employee}', [EmployeesPageController::class, 'show'])->whereUuid('employee');
    Route::post('employees/{employee}/employment', [EmployeesPageController::class, 'change'])->whereUuid('employee');

    Route::get('payroll', [PayrollRunsPageController::class, 'index']);
    Route::post('payroll', [PayrollRunsPageController::class, 'calculate']);
    Route::get('payroll/{run}', [PayrollRunsPageController::class, 'show'])->whereUuid('run');
    Route::post('payroll/{run}/approve', [PayrollRunsPageController::class, 'approve'])->whereUuid('run')->middleware('moves-money');
    Route::post('payroll/{run}/pay', [PayrollRunsPageController::class, 'pay'])->whereUuid('run')->middleware('moves-money');
    Route::get('payroll/{run}/bank-files/{file}', [PayrollRunsPageController::class, 'bankFile'])->whereUuid(['run', 'file']);

    Route::get('payslips', [PayrollRunsPageController::class, 'payslips']);
    Route::get('payslips/{payslip}/pdf', [PayrollRunsPageController::class, 'payslipPdf'])->whereUuid('payslip');

    Route::get('payroll-settings', [PayrollSettingsPageController::class, 'index']);
    Route::put('payroll-settings', [PayrollSettingsPageController::class, 'saveSettings']);
    Route::put('payroll-settings/grades/{grade}', [PayrollSettingsPageController::class, 'saveStructure'])->whereUuid('grade');
    Route::put('payroll-settings/tax-slabs', [PayrollSettingsPageController::class, 'saveSlabs']);
});
