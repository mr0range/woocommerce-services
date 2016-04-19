import React from 'react';
import FormFieldset from 'components/forms/form-fieldset';
import FormLabel from 'components/forms/form-label';
import FormLegend from 'components/forms/form-legend';
import FormButton from 'components/forms/form-button';
import FormRadio from 'components/forms/form-radio';
import PackagesList from './packages-list';
import AddPackageDialog from './add-package';

export default React.createClass( {
	displayName: 'Packages',
	getInitialState: function() {
		return {
			addingPackage: false,
		};
	},
	render: function() {
		return (
			<div>
				<FormFieldset>
					<FormLegend>Packing method</FormLegend>
					<FormLabel>
						<FormRadio value="box_packing" checked={ true } readOnly={ true } />
						<span>When cheaper, pack multiple items in a single package</span>
					</FormLabel>
					<FormLabel>
						<FormRadio value="per_item" checked={ false } readOnly={ true } />
						<span>Ship items individually, in their original packaging</span>
					</FormLabel>
				</FormFieldset>
				<PackagesList { ...this.props } />
				{ this.renderAddPackage() }
				<FormFieldset className="add-package-button-field">
					<FormButton
						type="button"
						isPrimary={ false }
						compact
						onClick={ () => this.setState( { addingPackage: true } ) }
					>
						Add a package
					</FormButton>
				</FormFieldset>
			</div>
		);
	},
	renderAddPackage: function() {
		if ( this.state.addingPackage ) {
			return (
				<AddPackageDialog { ...this.props } onClose={ () => this.setState( { addingPackage: false } ) } />
			);
		}
	},
} );