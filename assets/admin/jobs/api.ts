import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';

export interface Lane {
	queue: string;
	types: string[];
	due: number;
	running: number;
	later: number;
	oldest_at: string | null;
	next_at: string | null;
	workers: number;
}

export interface JobType {
	type: string;
	queue: string;
	due: number;
	running: number;
	later: number;
	failed: number;
}

export interface Worker {
	name: string;
	queues: string[];
	started_at: string;
	seen_at: string;
	working: string | null;
	jobs_done: number;
	memory: number;
}

export interface ScheduledJob {
	id: string;
	type: string;
	unique_key: string | null;
	at: string;
	about: string;
}

export interface JobCounts {
	at: string;
	type: string;
	done: number;
	retried: number;
	failed: number;
	average_ms: number;
}

export interface Overview {
	lanes: Lane[];
	types: JobType[];
	workers: Worker[];
	runner: 'workers' | 'cron' | 'none';
	cron_ran_at: string | null;
	/** WP-Cron does not run on visits (DISABLE_WP_CRON). */
	cron_disabled: boolean;
	failed: number;
	scheduled: ScheduledJob[];
	hours: JobCounts[];
	days: JobCounts[];
	now: string;
}

export interface FailedJob {
	id: string;
	queue: string;
	type: string;
	unique_key: string | null;
	payload: Record< string, unknown >;
	error: string;
	attempts: number;
	failed_at: string;
	about: string;
}

export interface FailedJobPage {
	jobs: FailedJob[];
	total: number;
	page: number;
	pages: number;
}

/** Failed jobs by id, or all of them. */
export type Choice = { ids: string[] } | { all: true };

const BASE = '/orillacart/v1';

export function fetchOverview(): Promise< Overview > {
	return apiFetch< Overview >( { path: `${ BASE }/jobs` } );
}

export function fetchFailedJobs( query: {
	type?: string | undefined;
	page: number;
	per_page: number;
} ): Promise< FailedJobPage > {
	return apiFetch< FailedJobPage >( {
		path: addQueryArgs( `${ BASE }/jobs/failed`, { ...query } ),
	} );
}

export function retryFailedJobs(
	choice: Choice
): Promise< { retried: number } > {
	return apiFetch< { retried: number } >( {
		path: `${ BASE }/jobs/failed/retry`,
		method: 'POST',
		data: choice,
	} );
}

export function deleteFailedJobs(
	choice: Choice
): Promise< { deleted: number } > {
	return apiFetch< { deleted: number } >( {
		path: `${ BASE }/jobs/failed/delete`,
		method: 'POST',
		data: choice,
	} );
}

export function restartWorkers(): Promise< Overview > {
	return apiFetch< Overview >( {
		path: `${ BASE }/jobs/workers/restart`,
		method: 'POST',
	} );
}
