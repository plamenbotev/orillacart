import { Notice } from '@wordpress/components';
import type { ReactNode } from 'react';

import { Toasts } from './toasts';

declare global {
	interface Window {
		/** Where the screen is in the Shop menu; printed by AdminPageRegistrar. */
		orillacartAdmin?: {
			page: string;
			group: string;
			tabs: { id: string; label: string; href: string }[];
		};
	}
}

/** The screen's place in the menu: its group's name and tabs, when it is one of several. */
export function adminNavigation(): {
	group: string;
	page: string;
	tabs: Tab[];
} | null {
	const navigation = window.orillacartAdmin;
	if ( ! navigation || navigation.tabs.length < 2 ) {
		return null;
	}

	return navigation;
}

export interface Tab {
	id: string;
	label: string;
	/** Where it leads: a link; without one, the tab calls onSelect. */
	href?: string;
}

export interface PageProps {
	title: string;
	/** One line about the screen; a paragraph goes in a Section's description or a Hint. */
	description?: ReactNode;
	/** Something to the right of the title: a badge, a count. */
	titleAside?: ReactNode;
	/** The actions, the primary one first. */
	actions?: ReactNode;
	/** A link back to the list this came from. */
	back?: { label: string; href?: string; onClick?: () => void };
	tabs?: Tab[];
	selectedTab?: string;
	onSelectTab?: ( id: string ) => void;
	/** The header stays at the top while a long view scrolls: a detail view's actions are always at hand. */
	sticky?: boolean;
	/** A problem the screen met, above everything: a message, or null for none. */
	error?: string | null;
	onDismissError?: () => void;
	children?: ReactNode;
}

/**
 * The frame of every admin screen (design 52): a title, one line about it, the actions on the right, the tabs of
 * a grouped screen, and the toasts. Screens put their content inside it.
 *
 * A screen that is one tab of its menu group (Catalog, Settings…) shows the group's name as its title and the
 * tabs under it, unless it brought tabs of its own or a back link: a detail view keeps its own title.
 *
 * @param props
 * @param props.title          The screen's title.
 * @param props.description    One line about the screen.
 * @param props.titleAside     Something to the right of the title.
 * @param props.actions        The actions, the primary one first.
 * @param props.back           A link back to the list this came from.
 * @param props.tabs           The tabs of a grouped screen.
 * @param props.selectedTab    The id of the selected tab.
 * @param props.onSelectTab    Called with a tab's id.
 * @param props.sticky         Whether the header stays at the top while the view scrolls.
 * @param props.error          A problem the screen met, or null.
 * @param props.onDismissError Dismisses the problem.
 * @param props.children       The screen's content.
 */
export function Page( {
	title,
	description,
	titleAside,
	actions,
	back,
	tabs,
	selectedTab,
	onSelectTab,
	sticky = false,
	error = null,
	onDismissError,
	children,
}: PageProps ) {
	const navigation =
		tabs === undefined && back === undefined ? adminNavigation() : null;
	if ( navigation ) {
		tabs = navigation.tabs;
		selectedTab = navigation.page;
		title = navigation.group;
	}

	return (
		<div className="oc-page">
			<header
				className={
					sticky
						? 'oc-page__header oc-page__header--sticky'
						: 'oc-page__header'
				}
			>
				<div className="oc-page__heading">
					{ back &&
						( back.href !== undefined ? (
							<a
								className="oc-page__back"
								href={ back.href }
								onClick={ ( event ) => {
									if ( back.onClick ) {
										event.preventDefault();
										back.onClick();
									}
								} }
							>
								← { back.label }
							</a>
						) : (
							<button
								type="button"
								className="oc-page__back components-button is-link"
								onClick={ back.onClick }
							>
								← { back.label }
							</button>
						) ) }
					<div className="oc-page__title">
						<h1>{ title }</h1>
						{ titleAside }
					</div>
					{ description && (
						<p className="oc-page__description">{ description }</p>
					) }
				</div>
				{ actions && (
					<div className="oc-page__actions">{ actions }</div>
				) }
			</header>
			{ tabs && tabs.length > 0 && (
				<nav className="oc-tabs" aria-label={ title }>
					{ tabs.map( ( tab ) =>
						tab.href !== undefined ? (
							<a
								key={ tab.id }
								className="oc-tabs__tab"
								href={ tab.href }
								aria-current={
									tab.id === selectedTab ? 'page' : undefined
								}
								onClick={ ( event ) => {
									if ( onSelectTab ) {
										event.preventDefault();
										onSelectTab( tab.id );
									}
								} }
							>
								{ tab.label }
							</a>
						) : (
							<button
								key={ tab.id }
								type="button"
								className="oc-tabs__tab"
								role="tab"
								aria-selected={ tab.id === selectedTab }
								onClick={ () => onSelectTab?.( tab.id ) }
							>
								{ tab.label }
							</button>
						)
					) }
				</nav>
			) }
			{ error !== null && (
				<Notice
					status="error"
					isDismissible={ onDismissError !== undefined }
					onRemove={ onDismissError ?? ( () => {} ) }
				>
					{ error }
				</Notice>
			) }
			{ children }
			<Toasts />
		</div>
	);
}
