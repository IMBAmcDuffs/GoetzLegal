import attorneyCard from '../blocks/attorney-card/block.json';
import cta from '../blocks/cta/block.json';
import faqList from '../blocks/faq-list/block.json';
import hero from '../blocks/hero/block.json';
import practiceAreaItem from '../blocks/practice-area-item/block.json';
import practiceAreas from '../blocks/practice-areas/block.json';
import resourceLinks from '../blocks/resource-links/block.json';
import welcome from '../blocks/welcome/block.json';
import { AttorneyCardEdit } from './blocks/attorney-card/edit';
import { CtaEdit } from './blocks/cta/edit';
import { FaqListEdit } from './blocks/faq-list/edit';
import { HeroEdit } from './blocks/hero/edit';
import { PracticeAreaItemEdit } from './blocks/practice-area-item/edit';
import {
  PracticeAreasEdit,
  save as savePracticeAreas,
} from './blocks/practice-areas/edit';
import { ResourceLinksEdit } from './blocks/resource-links/edit';
import { WelcomeEdit } from './blocks/welcome/edit';

export const stableBlocks = [
  attorneyCard,
  cta,
  faqList,
  hero,
  practiceAreaItem,
  practiceAreas,
  resourceLinks,
  welcome,
];

const editors = {
  'goetz/attorney-card': AttorneyCardEdit,
  'goetz/cta': CtaEdit,
  'goetz/faq-list': FaqListEdit,
  'goetz/hero': HeroEdit,
  'goetz/practice-area-item': PracticeAreaItemEdit,
  'goetz/practice-areas': PracticeAreasEdit,
  'goetz/resource-links': ResourceLinksEdit,
  'goetz/welcome': WelcomeEdit,
};

export function registerStableBlocks(registerBlockType) {
  stableBlocks.forEach(({ name }) => {
    registerBlockType(name, {
      edit: editors[name],
      save: name === 'goetz/practice-areas' ? savePracticeAreas : () => null,
    });
  });
}
