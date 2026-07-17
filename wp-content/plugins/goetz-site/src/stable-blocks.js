import attorneyCard from '../blocks/attorney-card/block.json';
import cta from '../blocks/cta/block.json';
import faqList from '../blocks/faq-list/block.json';
import hero from '../blocks/hero/block.json';
import resourceLinks from '../blocks/resource-links/block.json';
import welcome from '../blocks/welcome/block.json';
import { AttorneyCardEdit } from './blocks/attorney-card/edit';
import { CtaEdit } from './blocks/cta/edit';
import { FaqListEdit } from './blocks/faq-list/edit';
import { HeroEdit } from './blocks/hero/edit';
import { ResourceLinksEdit } from './blocks/resource-links/edit';
import { WelcomeEdit } from './blocks/welcome/edit';

export const stableBlocks = [
  attorneyCard,
  cta,
  faqList,
  hero,
  resourceLinks,
  welcome,
];

const editors = {
  'goetz/attorney-card': AttorneyCardEdit,
  'goetz/cta': CtaEdit,
  'goetz/faq-list': FaqListEdit,
  'goetz/hero': HeroEdit,
  'goetz/resource-links': ResourceLinksEdit,
  'goetz/welcome': WelcomeEdit,
};

export function registerStableBlocks(registerBlockType) {
  stableBlocks.forEach(({ name }) => {
    registerBlockType(name, {
      edit: editors[name],
      save: () => null,
    });
  });
}
