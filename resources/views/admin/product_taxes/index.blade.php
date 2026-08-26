@extends('layouts.master')

@section('title', 'Product Taxes')

@section('content')

    {{-- PAGE HEADER --}}
    <div class="row">
        <div class="col-12">
            <div class="page-title-box d-sm-flex align-items-center justify-content-between">

                <h4 class="mb-sm-0 font-size-18">
                    Product Taxes
                </h4>

                <div class="page-title-right">

                    <a href="{{ route('admin.product_taxes.create.index') }}" class="btn btn-soft-info">

                        <i class="fas fa-plus"></i>
                        Create

                    </a>

                </div>

            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-12">
            <div class="card">

                <div class="card-header d-flex justify-content-between align-items-center">
                    <h4 class="card-title mb-0">Filter</h4>

                    <button type="button" id="reset-filter-btn" class="btn btn-light">

                        <i class="fa fa-undo"></i>
                        Reset

                    </button>
                </div>

                <div class="card-body">

                    <div class="row">

                        {{-- STATE --}}
                        <div class="col-md-6">

                            <label class="form-label fw-bold">
                                State
                            </label>

                            <select id="state_code" class="form-control select2-class2" data-placeholder="Select State">

                                <option value="">All States</option>

                                @foreach ($states as $state)
                                    <option value="{{ $state->state_code }}">
                                        {{ $state->state_name }}
                                        ({{ $state->state_code }})
                                    </option>
                                @endforeach

                            </select>

                        </div>


                        {{-- STATUS --}}
                        <div class="col-md-6">

                            <label class="form-label fw-bold">
                                Status
                            </label>

                            <select id="status" class="form-control select2-class2" data-placeholder="Select Status">

                                <option value="">All Status</option>
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>

                            </select>

                        </div>

                    </div>

                </div>

            </div>
        </div>
    </div>

    {{-- TABLE --}}
    <div class="row">

        <div class="col-12">

            <div class="card border">

                <div class="card-body">

                    <table id="data-table" class="table table-bordered dt-responsive nowrap w-100">

                        <thead>

                            <tr>

                                <th>State</th>

                                <th>State Code</th>

                                <th>State Rate</th>

                                <th>Local Rate</th>

                                <th>Combined Rate</th>

                                <th>Status</th>

                                <th class="text-center">
                                    Action
                                </th>

                            </tr>

                        </thead>

                        <tbody></tbody>

                    </table>

                </div>

            </div>

        </div>

    </div>

@endsection


@section('script')

    <script>
        $(function() {

            let table = $('#data-table').DataTable({

                processing: true,

                serverSide: true,

                ajax: {

                    url: "{{ route('admin.product_taxes.list') }}",

                    data: function(d) {

                        d.status = $('#status').val();

                        d.state_code = $('#state_code').val();

                    }

                },

                columns: [

                    {
                        data: 'state_name',
                        name: 'state_name'
                    },

                    {
                        data: 'state_code',
                        name: 'state_code',
                        className: 'text-center',

                        render: function(data) {

                            return `<span class="badge bg-info">
                                    ${data || '-'}
                                </span>`;
                        }
                    },

                    {
                        data: 'state_rate',
                        name: 'state_rate',

                        render: function(data) {

                            return `${parseFloat(data || 0).toFixed(2)}%`;

                        }
                    },

                    {
                        data: 'local_rate',
                        name: 'local_rate',

                        render: function(data) {

                            return `${parseFloat(data || 0).toFixed(2)}%`;

                        }
                    },

                    {
                        data: 'combined_rate',
                        name: 'combined_rate',

                        render: function(data) {

                            return `<strong>
                                    ${parseFloat(data || 0).toFixed(2)}%
                                </strong>`;

                        }
                    },

                    {
                        data: null,
                        name: 'status',
                        className: 'text-center',

                        render: function(data, type, row) {

                            return `
                            <div class="square-switch">

                                <input
                                    type="checkbox"
                                    id="status-switch-${row.id}"
                                    class="change-status"
                                    switch="status"
                                    data-id="${row.id}"
                                    ${row.status == 1 ? 'checked' : ''}
                                />

                                <label
                                    for="status-switch-${row.id}"
                                    data-on-label="Yes"
                                    data-off-label="No">
                                </label>

                            </div>
                        `;

                        }

                    },

                    {
                        data: null,

                        orderable: false,

                        searchable: false,

                        className: 'text-center',

                        render: function(data, type, row) {

                            return `

                            <a
                                href="{{ route('admin.product_taxes.update.index') }}/${row.id}"
                                class="btn btn-soft-info">

                                <i class="bx bx-pencil"></i>

                            </a>


                            <button
                                type="button"
                                class="btn btn-soft-danger delete-entry"
                                data-href="{{ route('admin.product_taxes.delete') }}/${row.id}">

                                <i class="bx bx-trash"></i>

                            </button>

                        `;

                        }

                    }

                ]

            });


            // CHANGE STATUS
            $(document).on('change', '.change-status', function() {

                let checkbox = $(this);

                let id = checkbox.data('id');

                let oldStatus = !checkbox.prop('checked');

                checkbox.prop('disabled', true);

                $.get(
                        `{{ route('admin.product_taxes.change.status') }}/${id}`
                    )

                    .done(function() {

                        table.ajax.reload(null, false);

                    })

                    .fail(function() {

                        checkbox.prop('checked', oldStatus);

                        showToastr(
                            'error',
                            'Unable to update status'
                        );

                    })

                    .always(function() {

                        checkbox.prop('disabled', false);

                    });

            });


            // STATUS FILTER
            $('#status').on('change', function() {

                table.ajax.reload();

            });


            // STATE FILTER
            $('#state_code').on('change', function() {

                table.ajax.reload();

            });

            // RESET FILTER
            $('#reset-filter-btn').on('click', function() {

                $('#state_code')
                    .val('')
                    .trigger('change');

                $('#status')
                    .val('')
                    .trigger('change');

            });

        });
    </script>

@endsection
