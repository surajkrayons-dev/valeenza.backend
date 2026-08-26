@extends('layouts.master')

@section('title', 'Update Product Tax')

@section('content')

    <div class="row">
        <div class="col-12">

            <div class="page-title-box d-flex justify-content-between">

                <h4>Update Product Tax</h4>

                <a href="{{ route('admin.product_taxes.index') }}" class="btn btn-primary">
                    <i class="fas fa-arrow-left"></i> Back
                </a>

            </div>

        </div>
    </div>


    <form id="updateFrm">

        @csrf

        <div class="card">

            <div class="card-body">

                <div class="row">

                    {{-- LEFT SIDE --}}
                    <div class="col-lg-8">

                        <div class="card mb-3">

                            <div class="card-body">

                                <div class="row">

                                    {{-- STATE NAME --}}
                                    <div class="col-md-8 mb-3">

                                        <label class="form-label fw-bold">
                                            State Name
                                            <sup class="text-danger fs-5">*</sup>
                                        </label>

                                        <input type="text" name="state_name" class="form-control"
                                            value="{{ $product_tax->state_name }}" maxlength="100"
                                            placeholder="Enter state name" required>

                                    </div>


                                    {{-- STATE CODE --}}
                                    <div class="col-md-4 mb-3">

                                        <label class="form-label fw-bold">
                                            State Code
                                            <sup class="text-danger fs-5">*</sup>
                                        </label>

                                        <input type="text" name="state_code" id="state_code"
                                            class="form-control text-uppercase"
                                            value="{{ strtoupper($product_tax->state_code) }}" maxlength="2" minlength="2"
                                            placeholder="e.g. CA" required>

                                        <small class="text-muted">
                                            Example: CA, TX, NY
                                        </small>

                                    </div>


                                    {{-- STATE RATE --}}
                                    <div class="col-md-6 mb-3">

                                        <label class="form-label fw-bold">
                                            State Rate (%)
                                            <sup class="text-danger fs-5">*</sup>
                                        </label>

                                        <input type="number" name="state_rate" id="state_rate" class="form-control"
                                            min="0" max="100" step="0.01"
                                            value="{{ number_format((float) $product_tax->state_rate, 2, '.', '') }}"
                                            placeholder="Enter state rate" required>

                                    </div>


                                    {{-- LOCAL RATE --}}
                                    <div class="col-md-6 mb-3">

                                        <label class="form-label fw-bold">
                                            Local Rate (%)
                                            <sup class="text-danger fs-5">*</sup>
                                        </label>

                                        <input type="number" name="local_rate" id="local_rate" class="form-control"
                                            min="0" max="100" step="0.01"
                                            value="{{ number_format((float) $product_tax->local_rate, 2, '.', '') }}"
                                            placeholder="Enter local rate" required>

                                    </div>


                                    {{-- COMBINED RATE --}}
                                    <div class="col-md-6 mb-3">

                                        <label class="form-label fw-bold">
                                            Combined Rate (%)
                                        </label>

                                        <input type="number" name="combined_rate" id="combined_rate" class="form-control"
                                            value="{{ number_format((float) $product_tax->combined_rate, 2, '.', '') }}"
                                            step="0.01" readonly>

                                        <small class="text-muted">
                                            Automatically calculated as State Rate + Local Rate.
                                        </small>

                                    </div>

                                </div>

                            </div>

                        </div>

                    </div>


                    {{-- RIGHT SIDE --}}
                    <div class="col-lg-4">

                        <div class="card">

                            <div class="card-header">

                                <h4 class="card-title mb-0">
                                    Product Tax Status
                                </h4>

                            </div>

                            <div class="card-body">

                                <div class="form-group d-flex justify-content-between align-items-center">

                                    <label class="form-label fw-bold">
                                        Status
                                    </label>

                                    <input type="hidden" name="status" value="0">

                                    <div class="square-switch">

                                        <input type="checkbox" id="square-status" name="status" switch="status"
                                            value="1" {{ $product_tax->status ? 'checked' : '' }}>

                                        <label for="square-status" data-on-label="Yes" data-off-label="No">
                                        </label>

                                    </div>

                                </div>

                            </div>

                        </div>

                    </div>

                </div>

            </div>


            {{-- FOOTER --}}
            <div class="card-footer text-end">

                <a href="{{ route('admin.product_taxes.index') }}" class="btn btn-secondary">
                    Cancel
                </a>

                <button type="button" id="updateBtn" class="btn btn-success">
                    Update
                </button>

            </div>

        </div>

    </form>

@endsection


@section('script')

    <script>
        $(document).ready(function() {


            // ==========================================
            // CALCULATE COMBINED RATE
            // ==========================================

            function calculateCombinedRate() {

                let stateRate =
                    parseFloat($('#state_rate').val()) || 0;

                let localRate =
                    parseFloat($('#local_rate').val()) || 0;

                let combinedRate =
                    stateRate + localRate;

                $('#combined_rate').val(
                    combinedRate.toFixed(2)
                );
            }


            // Recalculate whenever rate changes
            $('#state_rate, #local_rate').on('input', function() {

                calculateCombinedRate();

            });


            // ==========================================
            // STATE CODE UPPERCASE
            // ==========================================

            $('#state_code').on('input', function() {

                $(this).val(
                    $(this).val().toUpperCase()
                );

            });


            // ==========================================
            // UPDATE PRODUCT TAX
            // ==========================================

            $('#updateBtn').click(function(e) {

                e.preventDefault();

                let btn = $(this);

                let form = $('#updateFrm')[0];


                // Browser validation
                if (!form.checkValidity()) {

                    form.reportValidity();

                    return;

                }


                // Calculate latest combined rate
                calculateCombinedRate();


                let formData = new FormData(form);


                $.ajax({

                    url: "{{ route('admin.product_taxes.update', $product_tax->id) }}",

                    type: "POST",

                    data: formData,

                    processData: false,

                    contentType: false,


                    beforeSend: function() {

                        btn.prop('disabled', true);

                        showToastr(
                            'info',
                            'Updating...'
                        );

                    },


                    success: function(res) {

                        showToastr(
                            'success',
                            res.message
                        );


                        window.location.href =
                            "{{ route('admin.product_taxes.index') }}";

                    },


                    error: function(xhr) {

                        btn.prop('disabled', false);

                        showToastr(
                            'error',
                            formatErrorMessage(xhr)
                        );

                    }

                });

            });


            // Calculate once when page loads
            calculateCombinedRate();

        });
    </script>

@endsection
