import { __ } from '@wordpress/i18n';
import { registerBlockType } from '@wordpress/blocks';
import { TextControl, ToggleControl, PanelBody } from '@wordpress/components';
import { useBlockProps, InspectorControls } from '@wordpress/block-editor';

registerBlockType('acemedia/password-block', {
    title: __('Password Block', 'acemedia-login-block'),
    category: 'common',
    attributes: {
        showPassword: {
            type: 'boolean',
            default: false,
        },
        placeholder: {
            type: 'string',
            default: __('Password', 'acemedia-login-block'),
        }
    },
    edit: ({ attributes, setAttributes }) => {
        const { showPassword, placeholder } = attributes;

        return (
            <div {...useBlockProps()}>
                <InspectorControls>
                    <PanelBody title={__('Password Block Settings', 'acemedia-login-block')}>
                        <ToggleControl
                            label={__('Allow users to show password', 'acemedia-login-block')}
                            checked={showPassword}
                            onChange={(value) => setAttributes({ showPassword: value })}
                        />
                        <TextControl
                            label={__('Placeholder', 'acemedia-login-block')}
                            value={placeholder}
                            onChange={(value) => setAttributes({ placeholder: value })}
                        />
                    </PanelBody>
                </InspectorControls>
                <TextControl
                    type="text" // Always show as password in the editor
                    placeholder={placeholder}
                    value={placeholder}
                    onChange={(value) => setAttributes({ placeholder: value })} // Update placeholder attribute on change
                />
                {showPassword && (
                    <span
                        style={{ cursor: 'pointer', fontSize: '0.8em', display: 'block', marginTop: '0.5em' }}
                        onClick={() => setAttributes({ showPassword: !showPassword })}
                    >{showPassword ? __('Hide Password', 'acemedia-login-block') : __('Show Password', 'acemedia-login-block')}</span>
                )}
            </div>
        );
    },
    save: () => {
        // No save function needed for server-side rendering
        return null;
    },
});