/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { useBlockProps, InnerBlocks } from '@wordpress/block-editor';
import { TextControl, PanelBody } from '@wordpress/components';
import { InspectorControls } from '@wordpress/block-editor';
import { registerBlockType } from '@wordpress/blocks';

/**
 * Block metadata
 */
registerBlockType('acemedia/login-block', {
    title: __('Login Block', 'acemedia-login-block'),
    icon: 'lock',
    category: 'common',
    attributes: {
        labelRemember: {
            type: 'string',
            default: __('Remember Me', 'acemedia-login-block'),
        },
        labelLogIn: {
            type: 'string',
            default: __('Log In', 'acemedia-login-block'),
        },
    },

    edit: ({ attributes, setAttributes }) => {
        const { labelRemember, labelLogIn } = attributes;

        // Template for inner blocks
        const TEMPLATE = [
            ['core/columns', { style: { spacing: { margin: '0px' } } }, [
                ['core/column', {}, [
                    ['core/paragraph', { content: __('<label for="log"><strong>Username:</strong></label>', 'acemedia-login-block'), align: 'right' }],
                ]],
                ['core/column', {}, [
                    ['acemedia/username-block'],
                ]],
            ]],
            ['core/columns', { style: { spacing: { margin: '0px' } } }, [
                ['core/column', {}, [
                    ['core/paragraph', { content: __('<label for="pwd"><strong>Password:</strong></label>', 'acemedia-login-block'), align: 'right' }],
                ]],
                ['core/column', {}, [
                    ['acemedia/password-block'],
                ]],
            ]],
            ['core/columns', { style: { spacing: { margin: '0px' } } }, [
                ['core/column', {}, [
                ]],
                ['core/column', {}, [
                    ['acemedia/remember-me-block', { label: labelRemember }],
                    ['core/buttons', {}, [
                        ['core/button', {
                            text: labelLogIn,
                            className: 'button',
                        }]
                    ]]
                ]],
            ]],
        ];

        return (
            <div {...useBlockProps()}>
                <InspectorControls>
                    <PanelBody title={__('Login Form Settings', 'acemedia-login-block')}>
                        {/* Removed the TextControl for redirect URL */}
                    </PanelBody>
                </InspectorControls>

                <form className="wp-block-login-form" method="post">
                    <InnerBlocks template={TEMPLATE} templateLock="all" />
                </form>
            </div>
        );
    },

    save: () => (
        <div className="wp-block-login-form">
            <form action={aceLoginBlock.loginUrl} method="post">
                <InnerBlocks.Content />
                <button type="submit" style={{ display: 'none' }}>Submit</button>
            </form>
        </div>
    ),
});
